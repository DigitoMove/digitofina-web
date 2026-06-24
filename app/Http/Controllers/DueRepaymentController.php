<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\DueRepaymentDemandService;
use App\Support\ApiSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class DueRepaymentController extends Controller
{
    public function index(Request $request, DueRepaymentDemandService $dueRepayments): View
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
        $payload = $dueRepayments->build($request->user(), $date, $branchId);
        $perPage = 25;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $demands = new LengthAwarePaginator(
            $payload['demands']->forPage($page, $perPage)->values(),
            $payload['demands']->count(),
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $request->query(),
            ],
        );
        $summary = $payload['summary'];

        return view('due-repayments.index', [
            'date' => $date,
            'demands' => $demands,
            'scheduledCount' => $summary['scheduled_count'],
            'todayInstallmentCount' => $summary['today_installment_count'],
            'arrearsInstallmentCount' => $summary['arrears_installment_count'],
            'todayAmount' => $summary['today_amount'],
            'arrearsAmount' => $summary['arrears_amount'],
            'totalAmount' => $summary['total_amount'],
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
