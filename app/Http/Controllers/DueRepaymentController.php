<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Services\LoanVisibilityService;
use App\Support\ApiSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DueRepaymentController extends Controller
{
    public function index(Request $request, LoanVisibilityService $visibility): View
    {
        abort_unless($request->user()->canPerform('manage-loans') || $request->user()->canPerform('post-repayments') || $request->user()->canPerform('view-reports') || $request->user()->canPerform('view-arrears'), 403);

        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);
        $isCashier = $request->user()->isTellerCashier();
        $date = $isCashier
            ? CarbonImmutable::today()
            : CarbonImmutable::parse($data['date'] ?? today()->toDateString());
        $canFilterBranch = ! $isCashier && ApiSecurity::hasGlobalBranchAccess($request->user());
        $branchId = $canFilterBranch ? (int) ($data['branch_id'] ?? 0) : (int) $request->user()->branch_id;
        $openScheduleScope = fn (Builder $query): Builder => $query
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<=', $date->toDateString());
        $todayScheduleScope = fn (Builder $query): Builder => $query
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', $date->toDateString());
        $arrearsScheduleScope = fn (Builder $query): Builder => $query
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<', $date->toDateString());
        $loanScope = fn (Builder $query): Builder => $visibility
            ->scopeRepaymentCalendar($query, $request->user())
            ->when($branchId, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId));
        $scheduleQuery = LoanSchedule::query()
            ->excludingCancelledLoans()
            ->forActiveArrearsLoans()
            ->whereHas('loan', $loanScope)
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<=', $date->toDateString());
        $todayAmount = (float) (clone $scheduleQuery)->whereDate('due_date', $date->toDateString())->sum('outstanding_amount');
        $arrearsAmount = (float) (clone $scheduleQuery)->whereDate('due_date', '<', $date->toDateString())->sum('outstanding_amount');
        $todayInstallmentCount = (clone $scheduleQuery)->whereDate('due_date', $date->toDateString())->count();
        $arrearsInstallmentCount = (clone $scheduleQuery)->whereDate('due_date', '<', $date->toDateString())->count();
        $loans = Loan::query()
            ->with(['client', 'officer', 'product'])
            ->withCount([
                'schedules as due_installments_count' => $todayScheduleScope,
                'schedules as arrears_installments_count' => $arrearsScheduleScope,
                'schedules as open_installments_count' => $openScheduleScope,
            ])
            ->withSum([
                'schedules as due_installments_amount' => $todayScheduleScope,
                'schedules as arrears_installments_amount' => $arrearsScheduleScope,
                'schedules as open_installments_amount' => $openScheduleScope,
            ], 'outstanding_amount')
            ->forOperationalListing()
            ->tap($loanScope)
            ->whereHas('schedules', $openScheduleScope)
            ->orderBy('loan_number')
            ->paginate(25)
            ->withQueryString();

        return view('due-repayments.index', [
            'date' => $date,
            'loans' => $loans,
            'scheduledCount' => $todayInstallmentCount + $arrearsInstallmentCount,
            'todayInstallmentCount' => $todayInstallmentCount,
            'arrearsInstallmentCount' => $arrearsInstallmentCount,
            'todayAmount' => $todayAmount,
            'arrearsAmount' => $arrearsAmount,
            'totalAmount' => $todayAmount + $arrearsAmount,
            'branches' => Branch::query()
                ->when(! $canFilterBranch, fn (Builder $query): Builder => $query->whereKey($request->user()->branch_id))
                ->orderBy('name')
                ->get(),
            'canFilterBranch' => $canFilterBranch,
            'selectedBranchId' => $branchId,
            'isCashier' => $isCashier,
        ]);
    }
}
