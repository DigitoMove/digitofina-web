<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Client;
use App\Models\FixedSavingsAccount;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\InvestmentTransaction;
use App\Models\InvestorAccount;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Organization;
use App\Models\Package;
use App\Models\SavingsAccount;
use App\Models\SavingsGoal;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\LoanVisibilityService;
use App\Services\ModuleService;
use App\Services\TenantContext;
use App\Support\ApiSecurity;
use App\Support\ModuleCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LoanVisibilityService $visibility): View|RedirectResponse
    {
        if (Auth::user()->isMember()) {
            return redirect()->route('member.dashboard');
        }

        $user = Auth::user();
        if ($user->hasRole('super-admin')) {
            return $this->superAdminDashboard($request);
        }

        $depositType = TransactionType::where('code', 'deposit')->first();
        $withdrawalType = TransactionType::where('code', 'withdrawal')->first();
        $enabledModules = $user->hasRole('super-admin') || ! $user->organization
            ? collect(ModuleCatalog::modules())->pluck('slug')->all()
            : app(ModuleService::class)->enabledSlugs($user->organization);
        $moduleIsEnabled = fn (string $slug): bool => in_array($slug, $enabledModules, true);
        $clientsEnabled = $moduleIsEnabled('clients');
        $loansEnabled = $moduleIsEnabled('loans');
        $savingsEnabled = $moduleIsEnabled('savings');
        $fixedDepositsEnabled = $moduleIsEnabled('fixed-deposits');
        $investmentsEnabled = $moduleIsEnabled('investments');
        $tellerEnabled = $moduleIsEnabled('teller');
        $usersEnabled = $moduleIsEnabled('users');
        $auditLogsEnabled = $moduleIsEnabled('audit-logs');
        $drawer = $user->drawerSessions()->with('drawer')->where('status', 'open')->latest()->first()?->drawer;
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();
        $canFilterCalendarBranch = ApiSecurity::hasGlobalBranchAccess($user);
        $selectedCalendarBranchId = $canFilterCalendarBranch ? $request->integer('calendar_branch_id') : (int) $user->branch_id;
        $calendarScope = fn (Builder $query): Builder => $visibility
            ->scopeRepaymentCalendar($query, $user)
            ->when($selectedCalendarBranchId, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId));
        $canViewRepaymentCalendarAmounts = ! ($user->isTellerCashier() || $user->hasRole('loan-officer'));
        $dueToday = collect();
        $dueTodayTotals = null;
        $nextRepayments = collect();
        $calendarCounts = collect();

        if ($loansEnabled) {
            $dueToday = LoanSchedule::query()
                ->with(['loan.client', 'loan.product'])
                ->excludingCancelledLoans()
                ->whereHas('loan', $calendarScope)
                ->where('outstanding_amount', '>', 0)
                ->where('status', '!=', 'paid')
                ->whereDate('due_date', $today->toDateString())
                ->orderBy('due_date')
                ->limit(10)
                ->get();
            $dueTodayTotals = LoanSchedule::query()
                ->excludingCancelledLoans()
                ->whereHas('loan', $calendarScope)
                ->where('outstanding_amount', '>', 0)
                ->where('status', '!=', 'paid')
                ->whereDate('due_date', $today->toDateString())
                ->selectRaw('count(*) as total, coalesce(sum(outstanding_amount), 0) as amount')
                ->first();
            $nextRepayments = LoanSchedule::query()
                ->with(['loan.client', 'loan.product'])
                ->excludingCancelledLoans()
                ->whereHas('loan', $calendarScope)
                ->where('outstanding_amount', '>', 0)
                ->where('status', '!=', 'paid')
                ->whereDate('due_date', '>=', $today->toDateString())
                ->orderBy('due_date')
                ->orderBy('installment_number')
                ->limit(10)
                ->get();
            $calendarCounts = LoanSchedule::query()
                ->excludingCancelledLoans()
                ->whereHas('loan', $calendarScope)
                ->whereDate('due_date', '>=', $monthStart->toDateString())
                ->whereDate('due_date', '<=', $monthEnd->toDateString())
                ->selectRaw("
                    due_date,
                    count(distinct loan_id) as total,
                    count(distinct case when outstanding_amount > 0 and status != 'paid' then loan_id end) as open_total,
                    count(distinct case when due_date >= ? and outstanding_amount > 0 and status != 'paid' then loan_id end) as upcoming_total,
                    count(distinct case when due_date < ? and outstanding_amount > 0 and status != 'paid' then loan_id end) as overdue_total,
                    coalesce(sum(case when outstanding_amount > 0 and status != 'paid' then outstanding_amount else 0 end), 0) as amount
                ", [$today->toDateString(), $today->toDateString()])
                ->groupBy('due_date')
                ->get()
                ->keyBy(fn (LoanSchedule $schedule): string => $schedule->due_date->toDateString());
        }
        $calendarDays = collect(CarbonPeriod::create($monthStart->startOfWeek(CarbonInterface::SUNDAY), $monthEnd->endOfWeek(CarbonInterface::SATURDAY)))
            ->map(function ($date) use ($calendarCounts, $monthStart, $today): array {
                $key = $date->toDateString();
                $count = $calendarCounts->get($key);

                return [
                    'date' => $date->copy(),
                    'is_current_month' => $date->month === $monthStart->month,
                    'is_today' => $date->isSameDay($today),
                    'is_past' => $date->lt($today),
                    'count' => (int) ($count?->total ?? 0),
                    'open_count' => (int) ($count?->open_total ?? 0),
                    'upcoming_count' => (int) ($count?->upcoming_total ?? 0),
                    'overdue_count' => (int) ($count?->overdue_total ?? 0),
                    'amount' => (float) ($count?->amount ?? 0),
                ];
            });
        $arrearsCount = $loansEnabled ? LoanSchedule::query()
            ->excludingCancelledLoans()
            ->forActiveArrearsLoans()
            ->whereHas('loan', $calendarScope)
            ->where('outstanding_amount', '>', 0)
            ->whereDate('due_date', '<', $today->toDateString())
            ->distinct('loan_id')
            ->count('loan_id') : 0;
        $arrearsAmount = $loansEnabled ? LoanSchedule::query()
            ->excludingCancelledLoans()
            ->forActiveArrearsLoans()
            ->whereHas('loan', $calendarScope)
            ->where('outstanding_amount', '>', 0)
            ->whereDate('due_date', '<', $today->toDateString())
            ->sum('outstanding_amount') : 0;
        $loanApplications = $loansEnabled ? Loan::query()
            ->whereIn('status', ['draft', 'under_review', 'submitted', 'pending_guarantor_approval', 'pending_collateral_verification', 'approved'])
            ->tap(fn (Builder $query) => $visibility->scope($query, $user))
            ->count() : 0;
        $cashierRecentTransactions = collect();

        if ($user->isTellerCashier() && $tellerEnabled) {
            $cashierRecentTransactions = Transaction::query()
                ->with([
                    'account.client',
                    'journalEntries.chartAccount',
                    'type',
                    'reversalRequests' => fn ($query) => $query->where('status', 'pending')->latest(),
                ])
                ->where('user_id', $user->id)
                ->latest('posted_at')
                ->limit(8)
                ->get();

            $this->markReversalAvailability($cashierRecentTransactions, $user);
        }

        return view('dashboard.index', [
            'totalClients' => $clientsEnabled ? Client::count() : 0,
            'activeAccounts' => ($savingsEnabled || $loansEnabled || $investmentsEnabled) ? Account::where('status', 'active')->count() : 0,
            'totalSavings' => $savingsEnabled ? Account::whereHas('accountType', fn ($query) => $query->where('category', 'savings'))->sum('balance') : 0,
            'savingsLedgerBalance' => $savingsEnabled ? SavingsAccount::sum('current_balance') : 0,
            'savingsGrowthThisMonth' => $savingsEnabled ? SavingsTransaction::whereIn('type', ['deposit', 'transfer_in'])
                ->whereBetween('transaction_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->sum('amount') - SavingsTransaction::whereIn('type', ['withdrawal', 'transfer_out'])
                ->whereBetween('transaction_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->sum('amount') : 0,
            'activeSavers' => $savingsEnabled ? SavingsAccount::where('status', 'active')->distinct('client_id')->count('client_id') : 0,
            'targetSavingsProgress' => $savingsEnabled ? (float) SavingsGoal::where('status', 'active')->sum('current_saved_amount') : 0,
            'targetSavingsAmount' => $savingsEnabled ? (float) SavingsGoal::where('status', 'active')->sum('target_amount') : 0,
            'fixedSavingsMaturingSoon' => $fixedDepositsEnabled ? FixedSavingsAccount::where('status', 'active')->whereBetween('maturity_date', [$today->toDateString(), $today->addDays(30)->toDateString()])->count() : 0,
            'dormantSavingsAccounts' => $savingsEnabled ? SavingsAccount::query()
                ->where(function (Builder $query) use ($today): void {
                    $query->where('status', 'dormant')
                        ->orWhereDate('last_activity_at', '<=', $today->subMonths(6)->toDateString());
                })
                ->count() : 0,
            'totalLoans' => $loansEnabled ? Loan::whereIn('status', ['approved', 'active'])->sum('principal_balance') : 0,
            'todayDeposits' => ($tellerEnabled && $depositType) ? Transaction::whereDate('posted_at', today())->where('transaction_type_id', $depositType->id)->sum('amount') : 0,
            'todayWithdrawals' => ($tellerEnabled && $withdrawalType) ? Transaction::whereDate('posted_at', today())->where('transaction_type_id', $withdrawalType->id)->sum('amount') : 0,
            'drawerBalance' => $tellerEnabled ? ($drawer?->current_balance ?? 0) : 0,
            'pendingApprovals' => Approval::where('status', 'pending')->count(),
            'branchCount' => $usersEnabled ? Branch::count() : 0,
            'staffCount' => $usersEnabled ? User::where('status', 'active')->count() : 0,
            'auditEventsToday' => $auditLogsEnabled ? AuditLog::whereDate('created_at', today())->count() : 0,
            'totalInvestors' => $investmentsEnabled ? InvestorAccount::where('status', 'active')->count() : 0,
            'totalActiveInvestments' => $investmentsEnabled ? InvestmentAccount::where('status', 'active')->count() : 0,
            'totalInvestmentBalance' => $investmentsEnabled ? InvestorAccount::sum('current_investment_balance') : 0,
            'totalReturnsPosted' => $investmentsEnabled ? InvestmentTransaction::where('type', 'return_posting')->sum('amount') : 0,
            'investmentsMaturingThisMonth' => $investmentsEnabled ? InvestmentAccount::whereIn('status', ['active', 'matured'])->whereBetween('maturity_date', [$monthStart->toDateString(), $monthEnd->toDateString()])->count() : 0,
            'investmentWithdrawalsThisMonth' => $investmentsEnabled ? InvestmentTransaction::where('type', 'withdrawal')->whereBetween('transaction_date', [$monthStart->toDateString(), $monthEnd->toDateString()])->sum('amount') : 0,
            'topInvestmentPlans' => $investmentsEnabled ? InvestmentPlan::query()
                ->withSum('investmentAccounts', 'current_balance')
                ->orderByDesc('investment_accounts_sum_current_balance')
                ->limit(3)
                ->get() : collect(),
            'dueToday' => $dueToday,
            'dueTodayCount' => (int) ($dueTodayTotals?->total ?? 0),
            'dueTodayAmount' => (float) ($dueTodayTotals?->amount ?? 0),
            'nextRepayments' => $nextRepayments,
            'cashierRecentTransactions' => $cashierRecentTransactions,
            'calendarDays' => $calendarDays,
            'calendarMonth' => $today,
            'canViewRepaymentCalendarAmounts' => $canViewRepaymentCalendarAmounts,
            'arrearsCount' => $arrearsCount,
            'arrearsAmount' => $arrearsAmount,
            'loanApplications' => $loanApplications,
            'canFilterCalendarBranch' => $canFilterCalendarBranch,
            'calendarBranches' => $loansEnabled ? Branch::orderBy('name')->get() : collect(),
            'selectedCalendarBranchId' => $selectedCalendarBranchId,
            'enabledModules' => $enabledModules,
            'alerts' => array_filter([
                'Loans in arrears' => $moduleIsEnabled('loans') ? $arrearsCount : null,
                'Open drawer' => $moduleIsEnabled('teller') ? ($drawer ? $drawer->name : 'No active teller drawer') : null,
                'Organization' => $user->organization?->name ?? 'Not assigned',
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function markReversalAvailability(Collection $transactions, User $user): void
    {
        $transactions->each(function (Transaction $transaction) use ($user): void {
            $hasPendingReversal = $transaction->reversalRequests->isNotEmpty();
            $canRequest = $transaction->status === 'posted'
                && ! $transaction->reversed_by
                && ! $hasPendingReversal
                && $this->canAdminReverseTransactions($user);

            $transaction->setAttribute('can_request_reversal', $canRequest);
            $transaction->setAttribute('reversal_lock_note', null);
        });
    }

    private function canAdminReverseTransactions(User $user): bool
    {
        return $user->hasRole('super-admin')
            || $user->hasRole('organization-admin')
            || $user->canPerform('approve-reversals')
            || $user->canPerform('override-maker-checker');
    }

    private function superAdminDashboard(Request $request): View
    {
        $selectedOrganization = app(TenantContext::class)->organization();
        $organizationIds = $selectedOrganization ? [$selectedOrganization->id] : null;
        $scope = fn ($query) => $organizationIds ? $query->whereIn('organization_id', $organizationIds) : $query;
        $enabledModules = $selectedOrganization
            ? app(ModuleService::class)->enabledSlugs($selectedOrganization)
            : collect(ModuleCatalog::modules())->pluck('slug')->all();

        return view('dashboard.super-admin', [
            'selectedOrganization' => $selectedOrganization,
            'enabledModules' => $enabledModules,
            'organizations' => Organization::query()->with('package')->orderBy('name')->get(),
            'packages' => Package::query()->where('status', 'active')->orderBy('name')->get(),
            'organizationCount' => Organization::query()->count(),
            'activeOrganizationCount' => Organization::query()->where('status', 'active')->count(),
            'suspendedOrganizationCount' => Organization::query()->whereIn('status', ['suspended', 'disabled', 'inactive'])->count(),
            'staffCount' => User::query()->whereNotNull('organization_id')->count(),
            'clientCount' => $scope(Client::query())->count(),
            'loanBalance' => (float) $scope(Loan::query())->sum('principal_balance'),
            'savingsBalance' => (float) $scope(SavingsAccount::query())->sum('current_balance'),
            'investmentBalance' => (float) $scope(InvestorAccount::query())->sum('current_investment_balance'),
            'recentOrganizations' => Organization::query()->with('package')->latest()->limit(6)->get(),
        ]);
    }
}
