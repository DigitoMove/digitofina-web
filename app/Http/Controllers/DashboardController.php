<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\LoanVisibilityService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(LoanVisibilityService $visibility): View|RedirectResponse
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
        $scheduleScope = fn (Builder $query): Builder => $visibility->scope($query, $user);
        $dueToday = LoanSchedule::query()
            ->with(['loan.client', 'loan.product'])
            ->whereHas('loan', $scheduleScope)
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', $today->toDateString())
            ->orderBy('due_date')
            ->limit(10)
            ->get();
        $dueTodayTotals = LoanSchedule::query()
            ->whereHas('loan', $scheduleScope)
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', $today->toDateString())
            ->selectRaw('count(*) as total, coalesce(sum(outstanding_amount), 0) as amount')
            ->first();
        $calendarCounts = LoanSchedule::query()
            ->whereHas('loan', $scheduleScope)
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->selectRaw('due_date, count(*) as total, sum(outstanding_amount) as amount')
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
            'totalLoans' => Loan::whereIn('status', ['approved', 'active'])->sum('principal_balance'),
            'todayDeposits' => $depositType ? Transaction::whereDate('posted_at', today())->where('transaction_type_id', $depositType->id)->sum('amount') : 0,
            'todayWithdrawals' => $withdrawalType ? Transaction::whereDate('posted_at', today())->where('transaction_type_id', $withdrawalType->id)->sum('amount') : 0,
            'drawerBalance' => $drawer?->current_balance ?? 0,
            'pendingApprovals' => Approval::where('status', 'pending')->count(),
            'branchCount' => Branch::count(),
            'staffCount' => User::where('status', 'active')->count(),
            'auditEventsToday' => AuditLog::whereDate('created_at', today())->count(),
            'dueToday' => $dueToday,
            'dueTodayCount' => (int) ($dueTodayTotals?->total ?? 0),
            'dueTodayAmount' => (float) ($dueTodayTotals?->amount ?? 0),
            'calendarDays' => $calendarDays,
            'calendarMonth' => $today,
            'arrearsCount' => $arrearsCount,
            'arrearsAmount' => $arrearsAmount,
            'loanApplications' => $loanApplications,
            'alerts' => [
                'Loans in arrears' => $arrearsCount,
                'Open drawer' => $drawer ? $drawer->name : 'No active teller drawer',
                'Organization' => $user->organization?->name ?? 'Not assigned',
            ],
        ]);
    }

}
