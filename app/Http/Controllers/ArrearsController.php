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

        $data = $request->validate([
            'bucket' => ['nullable', 'string', 'in:1-30,31-60,61-90,91-180,180+'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'loan_product_id' => ['nullable', 'integer', 'exists:loan_products,id'],
            'officer_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $canFilterBranch = ApiSecurity::hasGlobalBranchAccess($request->user());
        $branchId = $canFilterBranch ? (int) ($data['branch_id'] ?? 0) : (int) $request->user()->branch_id;
        $loanScope = fn (Builder $query): Builder => $visibility
            ->scopeRepaymentCalendar($query, $request->user())
            ->when($branchId, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId))
            ->when($data['loan_product_id'] ?? null, fn (Builder $query, int $productId): Builder => $query->where('loan_product_id', $productId))
            ->when($data['officer_id'] ?? null, fn (Builder $query, int $officerId): Builder => $query->where('officer_id', $officerId))
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

        $schedules = LoanSchedule::query()
            ->with(['loan.branch', 'loan.client', 'loan.officer', 'loan.product'])
            ->excludingCancelledLoans()
            ->forActiveArrearsLoans()
            ->whereHas('loan', $loanScope)
            ->where('outstanding_amount', '>', 0)
            ->where(function ($query): void {
                $query->where('status', 'overdue')
                    ->orWhereDate('due_date', '<', now()->toDateString());
            })
            ->when($data['bucket'] ?? null, function ($query, string $bucket): void {
                match ($bucket) {
                    '1-30' => $query->whereBetween('days_overdue', [1, 30]),
                    '31-60' => $query->whereBetween('days_overdue', [31, 60]),
                    '61-90' => $query->whereBetween('days_overdue', [61, 90]),
                    '91-180' => $query->whereBetween('days_overdue', [91, 180]),
                    '180+' => $query->where('days_overdue', '>', 180),
                    default => null,
                };
            })
            ->orderBy('due_date')
            ->paginate(20)
            ->withQueryString();

        $loanArrears = LoanSchedule::query()
            ->excludingCancelledLoans()
            ->forActiveArrearsLoans()
            ->whereHas('loan', $loanScope)
            ->whereIn('loan_id', $schedules->getCollection()->pluck('loan_id')->unique())
            ->where('outstanding_amount', '>', 0)
            ->whereDate('due_date', '<', now()->toDateString())
            ->selectRaw('loan_id, sum(outstanding_amount) as cumulative_due, max(days_overdue) as stored_days_overdue')
            ->groupBy('loan_id')
            ->get()
            ->keyBy('loan_id');

        return view('arrears.index', [
            'schedules' => $schedules,
            'loanArrears' => $loanArrears,
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
                ->whereHas('role', fn (Builder $query): Builder => $query->whereIn('slug', ['branch-manager', 'loan-officer']))
                ->orderBy('name')
                ->get(),
            'canFilterBranch' => $canFilterBranch,
            'selectedBranchId' => $branchId,
        ]);
    }

    public function applyPenalties(Request $request, LoanService $service): RedirectResponse
    {
        abort_unless(auth()->user()->canPerform('manage-loans'), 403);

        $canFilterBranch = ApiSecurity::hasGlobalBranchAccess($request->user());
        $loan = $request->integer('loan_id') ? Loan::findOrFail($request->integer('loan_id')) : null;
        $branchId = $canFilterBranch ? $request->integer('branch_id') : (int) $request->user()->branch_id;
        $count = $service->applyOverduePenalties($loan, branchId: $branchId ?: null);

        return back()->with('status', "{$count} overdue installment(s) checked.");
    }
}
