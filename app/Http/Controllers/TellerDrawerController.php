<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\ChartAccount;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\LoanSchedule;
use App\Models\TellerDrawer;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FinancialControlService;
use App\Services\TellerService;
use App\Services\TransactionPostingService;
use App\Support\CurrencyDenomination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class TellerDrawerController extends Controller
{
    public function index(Request $request): View
    {
        $canOpenForTellers = $request->user()->canPerform('manage-drawers') && $request->user()->canPerform('manage-users');
        $session = DrawerSession::with('drawer')->where('user_id', auth()->id())->where('status', 'open')->latest()->first();
        $account = $request->account_number
            ? Account::with(['client', 'accountType'])
                ->where('account_number', $request->account_number)
                ->when($request->integer('account_type_id'), fn ($query, int $typeId) => $query->where('account_type_id', $typeId))
                ->first()
            : null;
        $todayMovements = $session
            ? DrawerMovement::query()
                ->where('drawer_session_id', $session->id)
                ->whereDate('created_at', today())
                ->get()
            : collect();
        $cashIn = (float) $todayMovements->where('type', 'cash_in')->sum('amount');
        $cashOut = (float) $todayMovements->where('type', 'cash_out')->sum('amount');
        $branchId = $session?->drawer?->branch_id ?? $request->user()->branch_id;
        $dueToday = LoanSchedule::query()
            ->with(['loan.client', 'loan.loanAccount'])
            ->whereHas('loan', function (Builder $query) use ($branchId): void {
                $query->where('branch_id', $branchId);
            })
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', today())
            ->orderBy('due_date')
            ->limit(8)
            ->get();

        return view('teller.index', [
            'session' => $session,
            'drawer' => $session?->drawer,
            'drawers' => TellerDrawer::query()
                ->when(! $canOpenForTellers, fn ($query) => $query->where('assigned_user_id', auth()->id())->orWhereNull('assigned_user_id'))
                ->orderBy('code')
                ->get(),
            'tellers' => $canOpenForTellers
                ? User::query()
                    ->with('role')
                    ->where('organization_id', $request->user()->organization_id)
                    ->where('status', 'active')
                    ->whereHas('role', fn ($query) => $query->whereIn('slug', ['cashier', 'teller-cashier']))
                    ->orderBy('name')
                    ->get()
                : collect(),
            'canOpenForTellers' => $canOpenForTellers,
            'accountTypes' => AccountType::where('is_active', true)->get(),
            'chartAccounts' => ChartAccount::query()
                ->where('branch_id', $request->user()->branch_id)
                ->where('is_active', true)
                ->where('is_cash_account', false)
                ->whereIn('type', ['expense', 'income', 'asset', 'liability'])
                ->when($request->string('gl_search')->toString(), function ($query, string $search): void {
                    $query->whereAny(['code', 'name', 'type'], 'like', "%{$search}%");
                })
                ->orderBy('type')
                ->orderBy('name')
                ->get(),
            'transactionTypes' => TransactionType::where('is_active', true)->get(),
            'account' => $account,
            'recentMovements' => $session
                ? DrawerMovement::query()->with('session.drawer')->where('drawer_session_id', $session->id)->latest()->limit(8)->get()
                : collect(),
            'cashIn' => $cashIn,
            'cashOut' => $cashOut,
            'netCashMovement' => $cashIn - $cashOut,
            'dueToday' => $dueToday,
            'dueTodayAmount' => (float) $dueToday->sum('outstanding_amount'),
            'expectedBalance' => (float) ($session?->opening_balance ?? 0) + $cashIn - $cashOut,
        ]);
    }

    public function open(Request $request, TellerService $tellers, FinancialControlService $financialControls, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'teller_drawer_id' => ['required', 'exists:teller_drawers,id'],
            'teller_user_id' => ['nullable', 'exists:users,id'],
            'opening_balance' => ['required', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
        ]);

        try {
            $drawer = TellerDrawer::query()->findOrFail($data['teller_drawer_id']);
            $financialControls->ensureFinancialDayOpen($request->user(), (int) $drawer->branch_id, today());
            $teller = $this->resolveOpeningTeller($request, $data['teller_user_id'] ?? null);
            $session = $tellers->openDrawer($drawer, $teller, (float) $data['opening_balance'], $request->user());
            $auditLogger->record('drawer.opened', $session, $session->toArray() + ['opened_by' => $request->user()->id]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['opening_balance' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Drawer opened.');
    }

    public function post(Request $request, TransactionPostingService $posting): RedirectResponse
    {
        $data = $request->validate([
            'account_scope' => ['required', 'in:client_account,general_ledger'],
            'account_number' => ['nullable', 'required_if:account_scope,client_account', 'exists:accounts,account_number'],
            'chart_account_id' => ['nullable', 'required_if:account_scope,general_ledger', 'exists:chart_accounts,id'],
            'gl_entry_type' => ['nullable', 'required_if:account_scope,general_ledger', 'in:debit,credit'],
            'transaction_type_id' => ['nullable', 'required_if:account_scope,client_account', 'exists:transaction_types,id'],
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'cheque_number' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
        ]);

        $session = DrawerSession::where('user_id', auth()->id())->where('status', 'open')->latest()->firstOrFail();

        try {
            if ($data['account_scope'] === 'general_ledger') {
                $chartAccount = ChartAccount::query()
                    ->whereKey($data['chart_account_id'])
                    ->where('branch_id', $session->drawer->branch_id)
                    ->firstOrFail();

                $posting->postGeneralLedgerCash($chartAccount, $session, $data['gl_entry_type'], (float) $data['amount'], auth()->user(), $data['description'] ?? null);

                return back()->with('status', 'General ledger transaction posted.');
            }

            $account = Account::where('account_number', $data['account_number'])->firstOrFail();
            $type = TransactionType::findOrFail($data['transaction_type_id']);
            $transaction = $posting->postCash($account, $session, $type, (float) $data['amount'], auth()->user(), $data['description'] ?? null, $data['cheque_number'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('receipts.show', $transaction)->with('status', 'Transaction posted.');
    }

    public function close(Request $request, FinancialControlService $financialControls): RedirectResponse
    {
        $data = $request->validate(['closing_balance' => ['required', 'numeric', 'min:0', CurrencyDenomination::validationRule()]]);

        try {
            $session = DrawerSession::where('user_id', auth()->id())->where('status', 'open')->latest()->firstOrFail();
            $financialControls->closeDrawer($session, $request->user(), (float) $data['closing_balance']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['closing_balance' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Drawer closed.');
    }

    private function resolveOpeningTeller(Request $request, ?int $tellerUserId): User
    {
        $canOpenForTellers = $request->user()->canPerform('manage-drawers') && $request->user()->canPerform('manage-users');

        if ($canOpenForTellers && ! $tellerUserId) {
            throw new InvalidArgumentException('Select the teller or cashier to open this drawer for.');
        }

        if (! $tellerUserId) {
            return $request->user();
        }

        if (! $canOpenForTellers) {
            throw new InvalidArgumentException('You cannot open drawers for another teller.');
        }

        $teller = User::query()
            ->with('role')
            ->whereKey($tellerUserId)
            ->where('organization_id', $request->user()->organization_id)
            ->where('status', 'active')
            ->firstOrFail();

        if (! in_array($teller->role?->slug, ['cashier', 'teller-cashier'], true)) {
            throw new InvalidArgumentException('Select an active teller or cashier.');
        }

        return $teller;
    }
}
