<?php

namespace App\Http\Controllers;

use App\Jobs\RunEndOfDayProcess;
use App\Models\Branch;
use App\Models\BranchCashReconciliation;
use App\Models\BranchDailySummary;
use App\Models\Client;
use App\Models\DailyAccountingSummary;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\Loan;
use App\Models\TellerDrawer;
use App\Models\User;
use App\Services\EndOfDayService;
use App\Support\CurrencyDenomination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class EndOfDayController extends Controller
{
    public function index(Request $request, EndOfDayService $endOfDay): View
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-end-of-day') || $request->user()->canPerform('manage-drawers'), 403);

        $businessDate = $request->date('business_date') ?: today();
        $branch = Branch::query()->find($request->integer('branch_id')) ?: $request->user()->branch;
        abort_unless($branch, 404);

        if (! $request->user()->canPerform('manage-branches')) {
            abort_unless((int) $branch->id === (int) $request->user()->branch_id, 403);
        }

        $financialDay = $endOfDay->getOrOpenFinancialDay($branch, $businessDate, $request->user());
        $summary = BranchDailySummary::query()
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate->toDateString())
            ->first();
        $reconciliation = BranchCashReconciliation::query()
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate->toDateString())
            ->first();
        $accountingSummaries = DailyAccountingSummary::query()
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate->toDateString())
            ->orderBy('account_code')
            ->get();
        $drawerSessions = DrawerSession::query()
            ->with(['drawer', 'user'])
            ->whereHas('drawer', fn (Builder $query): Builder => $query->where('branch_id', $branch->id))
            ->whereDate('opened_at', $businessDate->toDateString())
            ->orderByDesc('opened_at')
            ->get();
        $tellerMovements = DrawerMovement::query()
            ->with(['session.drawer', 'session.user', 'user'])
            ->whereHas('session.drawer', fn (Builder $query): Builder => $query->where('branch_id', $branch->id))
            ->whereDate('created_at', $businessDate->toDateString())
            ->latest()
            ->limit(15)
            ->get();
        $drawerBalances = TellerDrawer::query()
            ->with('assignedUser')
            ->where('branch_id', $branch->id)
            ->orderBy('code')
            ->get();
        $periodStart = $businessDate->copy()->startOfDay();
        $periodEnd = $businessDate->copy()->endOfDay();
        $officerActivities = User::query()
            ->with('role')
            ->where('organization_id', $request->user()->organization_id)
            ->where('branch_id', $branch->id)
            ->where('status', 'active')
            ->whereHas('role', fn (Builder $query): Builder => $query->where('slug', '!=', 'member'))
            ->orderBy('name')
            ->get()
            ->map(function (User $officer) use ($branch, $periodStart, $periodEnd): array {
                $clientsAdded = Client::query()
                    ->where('branch_id', $branch->id)
                    ->where('officer_id', $officer->id)
                    ->whereBetween('created_at', [$periodStart, $periodEnd])
                    ->count();
                $loansCreated = Loan::query()
                    ->where('branch_id', $branch->id)
                    ->where(function (Builder $query) use ($officer): void {
                        $query
                            ->where('created_by', $officer->id)
                            ->orWhere(fn (Builder $query): Builder => $query->whereNull('created_by')->where('officer_id', $officer->id));
                    })
                    ->whereBetween('created_at', [$periodStart, $periodEnd]);

                return [
                    'officer' => $officer,
                    'clients_added' => $clientsAdded,
                    'loans_created' => (clone $loansCreated)->count(),
                    'approved_amount' => (float) (clone $loansCreated)->sum('approved_amount'),
                ];
            })
            ->filter(fn (array $row): bool => $row['clients_added'] > 0 || $row['loans_created'] > 0)
            ->values();

        return view('end-of-day.index', [
            'branches' => Branch::query()
                ->when(! $request->user()->canPerform('manage-branches'), fn (Builder $query): Builder => $query->whereKey($request->user()->branch_id))
                ->orderBy('name')
                ->get(),
            'branch' => $branch,
            'businessDate' => $businessDate,
            'financialDay' => $financialDay,
            'summary' => $summary,
            'reconciliation' => $reconciliation,
            'accountingSummaries' => $accountingSummaries,
            'drawerSessions' => $drawerSessions,
            'tellerMovements' => $tellerMovements,
            'drawerBalances' => $drawerBalances,
            'officerActivities' => $officerActivities,
        ]);
    }

    public function reconcile(Request $request, EndOfDayService $endOfDay): RedirectResponse
    {
        abort_unless($request->user()->canPerform('manage-end-of-day') || $request->user()->canPerform('manage-drawers') || $request->user()->canPerform('manage-accounting'), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'business_date' => ['required', 'date'],
            'counted_cash' => ['required', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $endOfDay->reconcile($this->authorizedBranch($request, (int) $data['branch_id']), $request->user(), $data['business_date'], (float) $data['counted_cash'], $data['notes'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['counted_cash' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Branch cash reconciled.');
    }

    public function process(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canPerform('manage-end-of-day') || $request->user()->canPerform('manage-accounting') || $request->user()->canPerform('manage-drawers'), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'business_date' => ['required', 'date'],
        ]);

        $branch = $this->authorizedBranch($request, (int) $data['branch_id']);
        RunEndOfDayProcess::dispatch($branch->id, $data['business_date'], $request->user()->id)->afterCommit();

        return back()->with('status', 'End-of-day processing queued.');
    }

    public function close(Request $request, EndOfDayService $endOfDay): RedirectResponse
    {
        abort_unless($request->user()->canPerform('manage-end-of-day') || $request->user()->canPerform('manage-accounting') || $request->user()->canPerform('manage-drawers'), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'business_date' => ['required', 'date'],
            'counted_cash' => ['required', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $endOfDay->close($this->authorizedBranch($request, (int) $data['branch_id']), $request->user(), $data['business_date'], (float) $data['counted_cash'], $data['notes'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['counted_cash' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Business day closed and processing completed.');
    }

    public function lock(Request $request, EndOfDayService $endOfDay): RedirectResponse
    {
        abort_unless($request->user()->canPerform('manage-end-of-day') || $request->user()->canPerform('manage-accounting') || $request->user()->canPerform('audit-system'), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'business_date' => ['required', 'date'],
        ]);

        try {
            $endOfDay->lock($this->authorizedBranch($request, (int) $data['branch_id']), $request->user(), $data['business_date']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['business_date' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Business day locked.');
    }

    private function authorizedBranch(Request $request, int $branchId): Branch
    {
        $branch = Branch::query()->findOrFail($branchId);

        if (! $request->user()->canPerform('manage-branches')) {
            abort_unless((int) $branch->id === (int) $request->user()->branch_id, 403);
        }

        return $branch;
    }
}
