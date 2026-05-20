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
use App\Models\SavingsAccount;
use App\Models\SavingsGoal;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\LoanVisibilityService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LoanVisibilityService $visibility): View|RedirectResponse
    {
        if (Auth::user()->isMember()) {
            return redirect()->route('member.dashboard');
        }

        $depositType = TransactionType::where('code', 'deposit')->first();
        $withdrawalType = TransactionType::where('code', 'withdrawal')->first();
        $user = Auth::user();
        $drawer = $user->drawerSessions()->with('drawer')->where('status', 'open')->latest()->first()?->drawer;
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();
        $canFilterCalendarBranch = $user->hasRole('super-admin') || $user->canPerform('manage-organization') || $user->canPerform('audit-system');
        $selectedCalendarBranchId = $canFilterCalendarBranch ? $request->integer('calendar_branch_id') : (int) $user->branch_id;
        $scheduleScope = fn (Builder $query): Builder => $visibility->scope($query, $user);
        $calendarScope = fn (Builder $query): Builder => $visibility
            ->scopeRepaymentCalendar($query, $user)
            ->when($selectedCalendarBranchId, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId));
        $dueToday = LoanSchedule::query()
            ->with(['loan.client', 'loan.product'])
            ->whereHas('loan', $calendarScope)
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', $today->toDateString())
            ->orderBy('due_date')
            ->limit(10)
            ->get();
        $dueTodayTotals = LoanSchedule::query()
            ->whereHas('loan', $calendarScope)
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', $today->toDateString())
            ->selectRaw('count(*) as total, coalesce(sum(outstanding_amount), 0) as amount')
            ->first();
        $nextRepayments = LoanSchedule::query()
            ->with(['loan.client', 'loan.product'])
            ->whereHas('loan', $calendarScope)
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '>=', $today->toDateString())
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->limit(10)
            ->get();
        $calendarCounts = LoanSchedule::query()
            ->whereHas('loan', $calendarScope)
            ->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->selectRaw("
                due_date,
                count(*) as total,
                sum(case when outstanding_amount > 0 and status != 'paid' then 1 else 0 end) as open_total,
                sum(case when due_date >= ? and outstanding_amount > 0 and status != 'paid' then 1 else 0 end) as upcoming_total,
                sum(case when due_date < ? and outstanding_amount > 0 and status != 'paid' then 1 else 0 end) as overdue_total,
                coalesce(sum(case when outstanding_amount > 0 and status != 'paid' then outstanding_amount else 0 end), 0) as amount
            ", [$today->toDateString(), $today->toDateString()])
            ->groupBy('due_date')
            ->get()
            ->keyBy(fn (LoanSchedule $schedule): string => $schedule->due_date->toDateString());
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
        $arrearsCount = LoanSchedule::query()
            ->whereHas('loan', $scheduleScope)
            ->where('outstanding_amount', '>', 0)
            ->whereDate('due_date', '<', $today->toDateString())
            ->distinct('loan_id')
            ->count('loan_id');
        $arrearsAmount = LoanSchedule::query()
            ->whereHas('loan', $scheduleScope)
            ->where('outstanding_amount', '>', 0)
            ->whereDate('due_date', '<', $today->toDateString())
            ->sum('outstanding_amount');
        $loanApplications = Loan::query()
            ->whereIn('status', ['draft', 'under_review', 'submitted', 'pending_guarantor_approval', 'pending_collateral_verification', 'approved'])
            ->tap(fn (Builder $query) => $visibility->scope($query, $user))
            ->count();

        return view('dashboard.index', [
            'totalClients' => Client::count(),
            'activeAccounts' => Account::where('status', 'active')->count(),
            'totalSavings' => Account::whereHas('accountType', fn ($query) => $query->where('category', 'savings'))->sum('balance'),
            'savingsLedgerBalance' => SavingsAccount::sum('current_balance'),
            'savingsGrowthThisMonth' => SavingsTransaction::whereIn('type', ['deposit', 'transfer_in'])
                ->whereBetween('transaction_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->sum('amount') - SavingsTransaction::whereIn('type', ['withdrawal', 'transfer_out'])
                ->whereBetween('transaction_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->sum('amount'),
            'activeSavers' => SavingsAccount::where('status', 'active')->distinct('client_id')->count('client_id'),
            'targetSavingsProgress' => (float) SavingsGoal::where('status', 'active')->sum('current_saved_amount'),
            'targetSavingsAmount' => (float) SavingsGoal::where('status', 'active')->sum('target_amount'),
            'fixedSavingsMaturingSoon' => FixedSavingsAccount::where('status', 'active')->whereBetween('maturity_date', [$today->toDateString(), $today->addDays(30)->toDateString()])->count(),
            'dormantSavingsAccounts' => SavingsAccount::where('status', 'dormant')
                ->orWhereDate('last_activity_at', '<=', $today->subMonths(6)->toDateString())
                ->count(),
            'totalLoans' => Loan::whereIn('status', ['approved', 'active'])->sum('principal_balance'),
            'todayDeposits' => $depositType ? Transaction::whereDate('posted_at', today())->where('transaction_type_id', $depositType->id)->sum('amount') : 0,
            'todayWithdrawals' => $withdrawalType ? Transaction::whereDate('posted_at', today())->where('transaction_type_id', $withdrawalType->id)->sum('amount') : 0,
            'drawerBalance' => $drawer?->current_balance ?? 0,
            'pendingApprovals' => Approval::where('status', 'pending')->count(),
            'branchCount' => Branch::count(),
            'staffCount' => User::where('status', 'active')->count(),
            'auditEventsToday' => AuditLog::whereDate('created_at', today())->count(),
            'totalInvestors' => InvestorAccount::where('status', 'active')->count(),
            'totalActiveInvestments' => InvestmentAccount::where('status', 'active')->count(),
            'totalInvestmentBalance' => InvestorAccount::sum('current_investment_balance'),
            'totalReturnsPosted' => InvestmentTransaction::where('type', 'return_posting')->sum('amount'),
            'investmentsMaturingThisMonth' => InvestmentAccount::whereIn('status', ['active', 'matured'])->whereBetween('maturity_date', [$monthStart->toDateString(), $monthEnd->toDateString()])->count(),
            'investmentWithdrawalsThisMonth' => InvestmentTransaction::where('type', 'withdrawal')->whereBetween('transaction_date', [$monthStart->toDateString(), $monthEnd->toDateString()])->sum('amount'),
            'topInvestmentPlans' => InvestmentPlan::query()
                ->withSum('investmentAccounts', 'current_balance')
                ->orderByDesc('investment_accounts_sum_current_balance')
                ->limit(3)
                ->get(),
            'dueToday' => $dueToday,
            'dueTodayCount' => (int) ($dueTodayTotals?->total ?? 0),
            'dueTodayAmount' => (float) ($dueTodayTotals?->amount ?? 0),
            'nextRepayments' => $nextRepayments,
            'calendarDays' => $calendarDays,
            'calendarMonth' => $today,
            'arrearsCount' => $arrearsCount,
            'arrearsAmount' => $arrearsAmount,
            'loanApplications' => $loanApplications,
            'canFilterCalendarBranch' => $canFilterCalendarBranch,
            'calendarBranches' => Branch::orderBy('name')->get(),
            'selectedCalendarBranchId' => $selectedCalendarBranchId,
            'alerts' => [
                'Loans in arrears' => $arrearsCount,
                'Open drawer' => $drawer ? $drawer->name : 'No active teller drawer',
                'Organization' => $user->organization?->name ?? 'Not assigned',
            ],
        ]);
    }
}
