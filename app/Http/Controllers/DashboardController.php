<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Client;
use App\Models\InvestmentPlan;
use App\Models\InvestorAccount;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Organization;
use App\Models\Package;
use App\Models\SavingsAccount;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\DueRepaymentDemandService;
use App\Services\LoanVisibilityService;
use App\Services\ModuleService;
use App\Services\SystemActivityService;
use App\Services\TenantContext;
use App\Services\TrafficAnalyticsService;
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
    public function __invoke(Request $request, LoanVisibilityService $visibility, DueRepaymentDemandService $dueRepayments, SystemActivityService $activity): View|RedirectResponse
    {
        if (Auth::user()->isMember()) {
            return redirect()->route('member.dashboard');
        }

        $user = Auth::user();
        if ($user->hasRole('super-admin')) {
            return $this->superAdminDashboard($request, $activity);
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
        $investmentsEnabled = $moduleIsEnabled('investments');
        $tellerEnabled = $moduleIsEnabled('teller');
        $usersEnabled = $moduleIsEnabled('users');
        $isCashierDashboard = $user->isTellerCashier();
        $isLoanOfficerDashboard = $user->isLoanOfficer();
        $isLoanRecoveryDashboard = $user->isLoanRecoveryOfficer();
        $isCompactDashboard = $isCashierDashboard || $isLoanOfficerDashboard || $isLoanRecoveryDashboard;
        $drawer = $user->drawerSessions()->with('drawer')->where('status', 'open')->latest()->first()?->drawer;
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();
        $canFilterCalendarBranch = ! $isCashierDashboard && ApiSecurity::hasGlobalBranchAccess($user);
        $selectedCalendarBranchId = $canFilterCalendarBranch ? $request->integer('calendar_branch_id') : (int) $user->branch_id;
        $calendarScope = fn (Builder $query): Builder => $visibility
            ->scopeRepaymentCalendar($query, $user)
            ->when($selectedCalendarBranchId, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId));
        $canViewRepaymentCalendarAmounts = ! ($isCashierDashboard || $isLoanOfficerDashboard);
        $showRepaymentScheduleWidgets = true;
        $arrearsCutoffDate = $today->copy()->subDay()->toDateString();
        $dueToday = collect();
        $dueTodayTotals = [
            'client_count' => 0,
            'today_installment_count' => 0,
            'arrears_installment_count' => 0,
            'scheduled_count' => 0,
            'today_amount' => 0.0,
            'arrears_amount' => 0.0,
            'total_amount' => 0.0,
        ];
        $nextRepayments = collect();
        $calendarCounts = collect();

        if ($loansEnabled) {
            $dueTodayPayload = $dueRepayments->build($user, $today, (int) $selectedCalendarBranchId);
            $dueToday = $dueTodayPayload['demands'];
            $dueTodayTotals = $dueTodayPayload['summary'];

            if ($showRepaymentScheduleWidgets) {
                $nextRepayments = LoanSchedule::query()
                    ->with(['loan.client', 'loan.product'])
                    ->excludingCancelledLoans()
                    ->forActiveArrearsLoans()
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
                    ->forActiveArrearsLoans()
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
                    ", [$arrearsCutoffDate, $arrearsCutoffDate])
                    ->groupBy('due_date')
                    ->get()
                    ->keyBy(fn (LoanSchedule $schedule): string => $schedule->due_date->toDateString());
            }

            if ($isCompactDashboard) {
                $nextRepayments = $dueToday;
            }

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
            ->eligibleForArrears($today)
            ->whereHas('loan', $calendarScope)
            ->distinct('loan_id')
            ->count('loan_id') : 0;
        $arrearsAmount = $loansEnabled ? LoanSchedule::query()
            ->excludingCancelledLoans()
            ->eligibleForArrears($today)
            ->whereHas('loan', $calendarScope)
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
                ->whereDate('posted_at', $today->toDateString())
                ->latest('posted_at')
                ->limit(8)
                ->get();

            $this->markReversalAvailability($cashierRecentTransactions, $user);
        }

        $transactionGraph = $this->moneyTransactionGraph($user, $tellerEnabled, $isCashierDashboard, $selectedCalendarBranchId ?: null, $today);

        return view('dashboard.index', [
            'totalClients' => $clientsEnabled ? Client::query()->count() : 0,
            'activeAccounts' => ($savingsEnabled || $loansEnabled || $investmentsEnabled) ? Account::query()->where('status', 'active')->count() : 0,
            'savingsLedgerBalance' => $savingsEnabled ? SavingsAccount::sum('current_balance') : 0,
            'totalLoans' => $loansEnabled ? Loan::query()
                ->whereIn('status', ['approved', 'disbursed', 'active', 'in_arrears', 'restructured'])
                ->tap(fn (Builder $query): Builder => $visibility->scope($query, $user))
                ->sum('principal_balance') : 0,
            'todayDeposits' => ($tellerEnabled && $depositType) ? Transaction::query()
                ->whereDate('posted_at', today())
                ->where('transaction_type_id', $depositType->id)
                ->when($isCashierDashboard, fn (Builder $query): Builder => $query->where('user_id', $user->id))
                ->sum('amount') : 0,
            'todayWithdrawals' => ($tellerEnabled && $withdrawalType) ? Transaction::query()
                ->whereDate('posted_at', today())
                ->where('transaction_type_id', $withdrawalType->id)
                ->when($isCashierDashboard, fn (Builder $query): Builder => $query->where('user_id', $user->id))
                ->sum('amount') : 0,
            'drawerBalance' => $tellerEnabled ? ($drawer?->current_balance ?? 0) : 0,
            'hasOpenDrawer' => $drawer !== null,
            'branchCount' => $usersEnabled ? Branch::count() : 0,
            'staffCount' => $usersEnabled ? User::staff()->where('status', 'active')->count() : 0,
            'totalInvestmentBalance' => $investmentsEnabled ? InvestorAccount::sum('current_investment_balance') : 0,
            'topInvestmentPlans' => $investmentsEnabled ? InvestmentPlan::query()
                ->withSum('investmentAccounts', 'current_balance')
                ->orderByDesc('investment_accounts_sum_current_balance')
                ->limit(3)
                ->get() : collect(),
            'dueToday' => $dueToday,
            'dueTodayCount' => (int) ($dueTodayTotals['client_count'] ?? 0),
            'dueTodayAmount' => (float) ($dueTodayTotals['total_amount'] ?? 0),
            'nextRepayments' => $nextRepayments,
            'cashierRecentTransactions' => $cashierRecentTransactions,
            'calendarDays' => $calendarDays,
            'calendarMonth' => $today,
            'activitySummary' => $user->hasRole('organization-admin') ? $activity->summary($user->organization_id) : null,
            'canViewRepaymentCalendarAmounts' => $canViewRepaymentCalendarAmounts,
            'showRepaymentScheduleWidgets' => $showRepaymentScheduleWidgets,
            'isLoanRecoveryDashboard' => $isLoanRecoveryDashboard,
            'arrearsCount' => $arrearsCount,
            'arrearsAmount' => $arrearsAmount,
            'loanApplications' => $loanApplications,
            'canFilterCalendarBranch' => $canFilterCalendarBranch,
            'calendarBranches' => $loansEnabled ? Branch::orderBy('name')->get() : collect(),
            'selectedCalendarBranchId' => $selectedCalendarBranchId,
            'transactionGraph' => $transactionGraph,
            'enabledModules' => $enabledModules,
            'alerts' => array_filter([
                'Loans in arrears' => $moduleIsEnabled('loans') ? $arrearsCount : null,
                'Open drawer' => $moduleIsEnabled('teller') ? ($drawer ? $drawer->name : 'No active teller drawer') : null,
                'Organization' => $user->organization?->name ?? 'Not assigned',
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function moneyTransactionGraph(User $user, bool $tellerEnabled, bool $isCashierDashboard, ?int $branchId, CarbonImmutable $today): Collection
    {
        if (! $tellerEnabled) {
            return collect();
        }

        $from = $today->subDays(6);
        $dateExpression = 'date(coalesce(transactions.posted_at, transactions.created_at))';
        $effectiveBranchId = $branchId;

        if (! $effectiveBranchId && ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id) {
            $effectiveBranchId = (int) $user->branch_id;
        }

        $rows = Transaction::query()
            ->join('transaction_types', 'transaction_types.id', '=', 'transactions.transaction_type_id')
            ->where('transactions.status', 'posted')
            ->whereRaw($dateExpression.' between ? and ?', [$from->toDateString(), $today->toDateString()])
            ->when($isCashierDashboard, fn (Builder $query): Builder => $query->where('transactions.user_id', $user->id))
            ->when($effectiveBranchId, fn (Builder $query, int $branchId): Builder => $query->where('transactions.branch_id', $branchId))
            ->selectRaw("{$dateExpression} as business_date")
            ->selectRaw("coalesce(sum(case when transaction_types.direction = 'credit' then transactions.amount else 0 end), 0) as money_in")
            ->selectRaw("coalesce(sum(case when transaction_types.direction = 'debit' then transactions.amount else 0 end), 0) as money_out")
            ->selectRaw('count(*) as transaction_count')
            ->groupByRaw($dateExpression)
            ->get()
            ->keyBy('business_date');

        $maxAmount = max(1.0, (float) $rows->max('money_in'), (float) $rows->max('money_out'));

        return collect(CarbonPeriod::create($from, $today))
            ->map(function ($date) use ($rows, $maxAmount): array {
                $key = $date->toDateString();
                $row = $rows->get($key);
                $moneyIn = (float) ($row?->money_in ?? 0);
                $moneyOut = (float) ($row?->money_out ?? 0);

                return [
                    'date' => $date->copy(),
                    'label' => $date->format('D'),
                    'money_in' => $moneyIn,
                    'money_out' => $moneyOut,
                    'net' => $moneyIn - $moneyOut,
                    'transaction_count' => (int) ($row?->transaction_count ?? 0),
                    'in_height' => max(4, (int) round(($moneyIn / $maxAmount) * 100)),
                    'out_height' => max(4, (int) round(($moneyOut / $maxAmount) * 100)),
                ];
            })
            ->values();
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

    private function superAdminDashboard(Request $request, SystemActivityService $activity): View
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
            'staffCount' => (int) $scope(User::query()->staff()->where('status', 'active')->whereNotNull('organization_id'))->count(),
            'activeAccountCount' => (int) $scope(Account::query()->where('status', 'active'))->count(),
            'branchCount' => (int) $scope(Branch::query()->where('status', 'active'))->count(),
            'clientCount' => $scope(Client::query())->count(),
            'loanBalance' => (float) $scope(Loan::query()->whereIn('status', ['approved', 'disbursed', 'active', 'in_arrears', 'restructured']))->sum('principal_balance'),
            'savingsBalance' => (float) $scope(SavingsAccount::query())->sum('current_balance'),
            'investmentBalance' => (float) $scope(InvestorAccount::query())->sum('current_investment_balance'),
            'recentOrganizations' => Organization::query()->with('package')->latest()->limit(6)->get(),
            'activitySummary' => $activity->summary($selectedOrganization?->id),
            'traffic' => app(TrafficAnalyticsService::class)->dashboard($request, $selectedOrganization?->id),
        ]);
    }
}
