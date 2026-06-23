<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\JournalEntry;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Support\ApiSecurity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use InvalidArgumentException;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->canManageExpenses($request), 403);

        $organizationId = $this->activeOrganizationId($request);
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $branchId = $request->integer('branch_id') ?: null;
        $type = $this->ledgerType($request->string('type')->toString());
        $search = $request->string('search')->trim()->toString();
        $from = filled($validated['from'] ?? null) ? $request->date('from')->startOfDay() : null;
        $to = filled($validated['to'] ?? null) ? $request->date('to')->endOfDay() : null;

        $branches = Branch::query()
            ->withoutGlobalScope('tenant')
            ->where('is_active', true)
            ->when($organizationId, fn (Builder $query, int $organizationId): Builder => $query->where('organization_id', $organizationId))
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn (Builder $query): Builder => $query->where('id', $request->user()->branch_id))
            ->orderBy('name')
            ->get();

        if ($branchId) {
            $branch = $branches->firstWhere('id', $branchId);
            abort_unless($branch && $request->user()->canAccessBranch($branch->id, $branch->organization_id), 403);
        }

        $postingsQuery = $this->postingsQuery($request)
            ->when($type, fn (Builder $query, string $type): Builder => $query->whereHas('journalEntries.chartAccount', fn (Builder $query): Builder => $query->where('type', $type)))
            ->when($organizationId, fn (Builder $query, int $organizationId): Builder => $query->whereHas('drawerSession.drawer.branch', fn (Builder $query): Builder => $query->where('organization_id', $organizationId)))
            ->when($branchId, fn (Builder $query, int $branchId): Builder => $query->where('transactions.branch_id', $branchId))
            ->tap(fn (Builder $query): Builder => $this->applyPeriod($query, $from, $to))
            ->when($search !== '', fn (Builder $query): Builder => $this->searchPostings($query, $search))
            ->latest('posted_at');

        $totals = $this->postingTotals($request, $organizationId, $branchId, $search, $from, $to);
        $totalAmount = (clone $postingsQuery)->sum('amount');
        $postings = $postingsQuery->paginate(20)->withQueryString();

        return view('expenses.index', [
            'branches' => $branches,
            'branchId' => $branchId,
            'postings' => $postings,
            'expenses' => $postings,
            'search' => $search,
            'from' => $validated['from'] ?? '',
            'to' => $validated['to'] ?? '',
            'type' => $type,
            'types' => $this->ledgerTypes(),
            'totals' => $totals,
            'totalAmount' => (float) $totalAmount,
        ]);
    }

    public function destroy(Request $request, Transaction $expense, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($this->canManageExpenses($request), 403);

        try {
            $deleted = DB::transaction(function () use ($expense, $request, $auditLogger): array {
                $expense = Transaction::query()
                    ->with(['journalEntries.chartAccount', 'journalEntries.lines', 'drawerSession.drawer.branch', 'user'])
                    ->whereKey($expense->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertPostingCanBeDeleted($request, $expense);

                $session = DrawerSession::query()
                    ->with('drawer')
                    ->whereKey($expense->drawer_session_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $drawer = TellerDrawer::query()
                    ->whereKey($session->drawer->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $movement = DrawerMovement::query()
                    ->where('drawer_session_id', $session->id)
                    ->where('reference', $expense->reference)
                    ->whereIn('type', ['cash_in', 'cash_out'])
                    ->first();

                if (! $movement) {
                    throw new InvalidArgumentException('This posting does not have a drawer movement to reverse.');
                }

                $amount = (float) $expense->amount;
                $cashImpact = (float) $movement->amount;
                $restoreType = $movement->type === 'cash_out' ? 'cash_in' : 'cash_out';

                if ($movement->type === 'cash_out') {
                    $drawer->increment('current_balance', $cashImpact);
                } else {
                    if ((float) $drawer->current_balance < $cashImpact) {
                        throw new InvalidArgumentException('This posting cannot be deleted because the drawer does not have enough cash to remove the original money-in amount.');
                    }

                    $drawer->decrement('current_balance', $cashImpact);
                }

                $drawer->refresh();

                DrawerMovement::query()
                    ->where('drawer_session_id', $session->id)
                    ->where('reference', $expense->reference)
                    ->whereIn('type', ['cash_in', 'cash_out'])
                    ->delete();

                DrawerMovement::create([
                    'drawer_session_id' => $session->id,
                    'user_id' => $expense->user_id,
                    'type' => $restoreType,
                    'amount' => $cashImpact,
                    'balance_after' => $drawer->current_balance,
                    'reference' => $expense->reference.'-RESTORE',
                    'description' => 'Ledger posting deleted by '.$request->user()->name.'. Drawer cash adjusted.',
                ]);

                foreach ($expense->journalEntries as $journalEntry) {
                    $journalEntry->lines()->delete();
                }

                JournalEntry::query()
                    ->where('transaction_id', $expense->id)
                    ->delete();

                $postingAccount = $expense->journalEntries->first(fn (JournalEntry $journalEntry): bool => array_key_exists($journalEntry->chartAccount?->type, $this->ledgerTypes()))?->chartAccount;
                $auditPayload = [
                    'transaction_id' => $expense->id,
                    'reference' => $expense->reference,
                    'amount' => $amount,
                    'charges' => (float) $expense->charges,
                    'cash_impact' => $cashImpact,
                    'ledger_type' => $postingAccount?->type,
                    'drawer_movement_removed' => $movement->type,
                    'drawer_movement_restored' => $restoreType,
                    'cashier_id' => $expense->user_id,
                    'drawer_id' => $drawer->id,
                    'drawer_balance_after' => $drawer->current_balance,
                ];

                $auditLogger->record('ledger_posting.deleted', $expense, $auditPayload);
                $expense->delete();

                return $auditPayload;
            });
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['expense' => $exception->getMessage()]);
        }

        $direction = $deleted['drawer_movement_restored'] === 'cash_in' ? 'restored to' : 'removed from';

        return back()->with('status', 'Ledger posting deleted and UGX '.number_format((float) $deleted['cash_impact'], 2).' '.$direction.' the cashier drawer.');
    }

    private function postingsQuery(Request $request): Builder
    {
        return Transaction::query()
            ->with([
                'drawerSession.drawer.branch',
                'drawerMovements',
                'journalEntries.chartAccount',
                'type',
                'user',
            ])
            ->whereHas('journalEntries.chartAccount', fn (Builder $query): Builder => $query->whereIn('type', array_keys($this->ledgerTypes())))
            ->whereHas('drawerSession.drawer')
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn (Builder $query): Builder => $query->where('transactions.branch_id', $request->user()->branch_id))
            ->whereIn('transactions.id', function ($query): void {
                $query->select('transaction_id')
                    ->from('journal_entries')
                    ->whereNotNull('transaction_id');
            });
    }

    /**
     * @return array<string, float>
     */
    private function postingTotals(Request $request, ?int $organizationId, ?int $branchId, string $search, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        if (! Schema::hasColumn('journal_entries', 'chart_account_id')) {
            return collect($this->ledgerTypes())->mapWithKeys(fn ($label, string $type): array => [$type => 0.0])->all();
        }

        $totals = (clone $this->postingsQuery($request))
            ->join('journal_entries', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->join('chart_accounts', 'chart_accounts.id', '=', 'journal_entries.chart_account_id')
            ->when($organizationId, fn (Builder $query, int $organizationId): Builder => $query->whereHas('drawerSession.drawer.branch', fn (Builder $query): Builder => $query->where('organization_id', $organizationId)))
            ->when($branchId, fn (Builder $query, int $branchId): Builder => $query->where('transactions.branch_id', $branchId))
            ->tap(fn (Builder $query): Builder => $this->applyPeriod($query, $from, $to))
            ->when($search !== '', fn (Builder $query): Builder => $this->searchPostings($query, $search))
            ->whereIn('chart_accounts.type', array_keys($this->ledgerTypes()))
            ->selectRaw('chart_accounts.type as ledger_type, SUM(transactions.amount) as total_amount')
            ->groupBy('chart_accounts.type')
            ->pluck('total_amount', 'ledger_type')
            ->map(fn ($amount): float => (float) $amount);

        return collect($this->ledgerTypes())
            ->mapWithKeys(fn ($label, string $type): array => [$type => (float) ($totals[$type] ?? 0)])
            ->all();
    }

    private function applyPeriod(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $query
            ->when($from, fn (Builder $query): Builder => $query->where('transactions.posted_at', '>=', $from))
            ->when($to, fn (Builder $query): Builder => $query->where('transactions.posted_at', '<=', $to));
    }

    private function searchPostings(Builder $query, string $search): Builder
    {
        $likeSearch = "%{$search}%";

        return $query->where(function (Builder $query) use ($likeSearch): void {
            $query->whereAny(['transactions.reference', 'transactions.description', 'transactions.status'], 'like', $likeSearch)
                ->orWhereHas('user', fn (Builder $query): Builder => $query->whereAny(['name', 'email'], 'like', $likeSearch))
                ->orWhereHas('drawerSession.drawer.branch', fn (Builder $query): Builder => $query->whereAny(['name', 'code'], 'like', $likeSearch))
                ->orWhereHas('journalEntries.chartAccount', fn (Builder $query): Builder => $query->whereAny(['code', 'name'], 'like', $likeSearch));
        });
    }

    private function assertPostingCanBeDeleted(Request $request, Transaction $expense): void
    {
        $isManagedLedgerPosting = $expense->journalEntries->contains(fn (JournalEntry $journalEntry): bool => array_key_exists($journalEntry->chartAccount?->type, $this->ledgerTypes()));

        if (! $isManagedLedgerPosting) {
            throw new InvalidArgumentException('Only drawer ledger postings can be deleted here.');
        }

        if (! $expense->drawer_session_id || ! $expense->drawerSession?->drawer) {
            throw new InvalidArgumentException('This ledger posting is not linked to a cashier drawer.');
        }

        abort_unless($request->user()->canAccessBranch($expense->branch_id, $expense->drawerSession->drawer->branch?->organization_id), 403);
    }

    private function canManageExpenses(Request $request): bool
    {
        return $request->user()->hasRole('super-admin')
            || $request->user()->hasRole('organization-admin')
            || $request->user()->canPerform('manage-accounting')
            || $request->user()->canPerform('manage-drawers');
    }

    private function activeOrganizationId(Request $request): ?int
    {
        return app(TenantContext::class)->id() ?: $request->user()->organization_id;
    }

    /**
     * @return array<string, string>
     */
    private function ledgerTypes(): array
    {
        return [
            'expense' => 'Expenses',
            'income' => 'Income',
            'asset' => 'Assets',
            'liability' => 'Liabilities',
            'equity' => 'Capital',
        ];
    }

    private function ledgerType(string $type): ?string
    {
        return array_key_exists($type, $this->ledgerTypes()) ? $type : null;
    }
}
