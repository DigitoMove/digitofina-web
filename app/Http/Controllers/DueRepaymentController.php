<?php

namespace App\Http\Controllers;

use App\Models\Branch;
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
        $dueScheduleScope = fn (Builder $query): Builder => $query
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', $date->toDateString());
        $scheduleQuery = LoanSchedule::query()
            ->with([
                'loan' => fn ($query) => $query
                    ->with(['client', 'officer', 'product'])
                    ->withCount(['schedules as due_installments_count' => $dueScheduleScope])
                    ->withSum(['schedules as due_installments_amount' => $dueScheduleScope], 'outstanding_amount'),
            ])
            ->excludingCancelledLoans()
            ->forActiveArrearsLoans()
            ->whereHas('loan', fn (Builder $query): Builder => $visibility
                ->scopeRepaymentCalendar($query, $request->user())
                ->when($branchId, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId)))
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', $date->toDateString());
        $totalAmount = (float) (clone $scheduleQuery)
            ->sum('outstanding_amount');
        $schedules = $scheduleQuery
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->paginate(25)
            ->withQueryString();

        return view('due-repayments.index', [
            'date' => $date,
            'schedules' => $schedules,
            'scheduledCount' => $schedules->total(),
            'totalAmount' => $totalAmount,
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
