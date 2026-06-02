<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\User;
use App\Services\BusinessCalendarService;
use App\Services\LoanService;
use App\Services\LoanVisibilityService;
use App\Support\ApiSecurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArrearsController extends Controller
{
    public function index(Request $request, LoanVisibilityService $visibility, BusinessCalendarService $calendar): View
    {
        abort_unless(auth()->user()->canPerform('manage-loans') || auth()->user()->canPerform('view-reports'), 403);
        abort_if($request->user()->isTellerCashier(), 403);

        $data = $request->validate([
            'bucket' => ['nullable', 'string', 'in:1-30,31-60,61-90,91-180,180+'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'loan_product_id' => ['nullable', 'integer', 'exists:loan_products,id'],
            'officer_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $canFilterBranch = ApiSecurity::hasGlobalBranchAccess($request->user());
        $branchId = $canFilterBranch ? (int) ($data['branch_id'] ?? 0) : (int) $request->user()->branch_id;
        $canFilterOfficer = ! $request->user()->hasRole('loan-officer');
        $loanScope = fn (Builder $query): Builder => $visibility
            ->scopeRepaymentCalendar($query, $request->user())
            ->when($branchId, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId))
            ->when($data['loan_product_id'] ?? null, fn (Builder $query, int $productId): Builder => $query->where('loan_product_id', $productId))
            ->when($canFilterOfficer && ($data['officer_id'] ?? null), fn (Builder $query): Builder => $query->where('officer_id', (int) $data['officer_id']))
            ->when($data['search'] ?? null, function (Builder $query, string $search): Builder {
                return $query->where(function (Builder $query) use ($search): void {
                    $query->where('loan_number', 'like', "%{$search}%")
                        ->orWhereHas('client', function (Builder $query) use ($search): void {
                            $query->where('client_number', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            });

        $arrearsScheduleScope = fn ($query) => $query
            ->excludingCancelledLoans()
            ->eligibleForArrears(now())
            ->when($data['bucket'] ?? null, function ($query, string $bucket): void {
                match ($bucket) {
                    '1-30' => $query->whereBetween('days_overdue', [1, 30]),
                    '31-60' => $query->whereBetween('days_overdue', [31, 60]),
                    '61-90' => $query->whereBetween('days_overdue', [61, 90]),
                    '91-180' => $query->whereBetween('days_overdue', [91, 180]),
                    '180+' => $query->where('days_overdue', '>', 180),
                    default => null,
                };
            });

        $loans = Loan::query()
            ->with([
                'branch',
                'client',
                'officer',
                'product',
                'schedules' => fn ($query) => $arrearsScheduleScope($query)->orderBy('due_date'),
            ])
            ->tap($loanScope)
            ->whereHas('schedules', $arrearsScheduleScope)
            ->withCount(['schedules as arrears_schedules_count' => $arrearsScheduleScope])
            ->withSum(['schedules as arrears_cumulative_due' => $arrearsScheduleScope], 'outstanding_amount')
            ->withSum(['schedules as arrears_penalty_due' => $arrearsScheduleScope], 'penalty_due')
            ->withSum(['schedules as arrears_penalty_paid' => $arrearsScheduleScope], 'penalty_paid')
            ->withMin(['schedules as arrears_earliest_due_date' => $arrearsScheduleScope], 'due_date')
            ->orderBy('arrears_earliest_due_date')
            ->orderBy('loan_number')
            ->paginate(20)
            ->withQueryString();

        return view('arrears.index', [
            'loans' => $loans,
            'calendar' => $calendar,
            'branches' => Branch::query()
                ->when(! $canFilterBranch, fn (Builder $query): Builder => $query->whereKey($request->user()->branch_id))
                ->orderBy('name')
                ->get(),
            'products' => LoanProduct::query()->where('status', 'active')->orderBy('name')->get(),
            'officers' => User::query()
                ->with('role')
                ->where('status', 'active')
                ->when($branchId, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId))
                ->when(! $canFilterOfficer, fn (Builder $query): Builder => $query->whereKey($request->user()->id))
                ->whereHas('role', fn (Builder $query): Builder => $query->whereIn('slug', ['branch-manager', 'loan-officer']))
                ->orderBy('name')
                ->get(),
            'canFilterBranch' => $canFilterBranch,
            'canFilterOfficer' => $canFilterOfficer,
            'selectedBranchId' => $branchId,
            'canRunManualPenalties' => $this->canRunManualPenalties($request),
        ]);
    }

    public function applyPenalties(Request $request, LoanService $service): RedirectResponse
    {
        abort_unless($this->canRunManualPenalties($request), 403);

        $canFilterBranch = ApiSecurity::hasGlobalBranchAccess($request->user());
        $loan = $request->integer('loan_id') ? Loan::findOrFail($request->integer('loan_id')) : null;

        $branchId = $canFilterBranch ? $request->integer('branch_id') : (int) $request->user()->branch_id;
        $summary = $service->applyOverduePenaltiesWithSummary($loan, branchId: $branchId ?: null);

        return back()->with('status', sprintf(
            'Checked %d overdue installment(s). Applied UGX %s in penalties to %d schedule(s).',
            $summary['checked'],
            number_format($summary['amount'], 2),
            $summary['applied_schedules'],
        ));
    }

    public function clearPenalties(Request $request, LoanService $service): RedirectResponse
    {
        abort_unless($this->canRunManualPenalties($request), 403);

        $data = $request->validate([
            'loan_id' => ['nullable', 'integer', 'exists:loans,id'],
            'loan_schedule_id' => ['nullable', 'integer', 'exists:loan_schedules,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $canFilterBranch = ApiSecurity::hasGlobalBranchAccess($request->user());
        $branchId = $canFilterBranch ? (int) ($data['branch_id'] ?? 0) : (int) $request->user()->branch_id;
        $loan = ($data['loan_id'] ?? null) ? Loan::query()->findOrFail((int) $data['loan_id']) : null;
        $schedule = ($data['loan_schedule_id'] ?? null)
            ? LoanSchedule::query()->with('loan')->findOrFail((int) $data['loan_schedule_id'])
            : null;

        if ($loan) {
            ApiSecurity::assertTenantAccess($request->user(), $loan);
        }

        if ($schedule?->loan) {
            ApiSecurity::assertTenantAccess($request->user(), $schedule->loan);
        }

        $totals = $service->clearPendingPenalties(
            loan: $loan,
            schedule: $schedule,
            branchId: $branchId ?: null,
            actor: $request->user(),
        );

        return back()->with('status', sprintf(
            'Cleared UGX %s in penalties from %d schedule(s).',
            number_format($totals['amount'], 2),
            $totals['schedules'],
        ));
    }

    private function canRunManualPenalties(Request $request): bool
    {
        return $request->user()?->hasAnyRole(['super-admin', 'organization-admin']) === true;
    }
}
