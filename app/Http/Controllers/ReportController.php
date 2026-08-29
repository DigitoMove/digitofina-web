<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\User;
use App\Services\ReportCatalogService;
use App\Services\TenantContext;
use App\Support\ApiSecurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request, ReportCatalogService $reports): View
    {
        $this->authorizeReportCatalogAccess($request->user());

        return view('reports.index', [
            'groups' => $reports->grouped($request->user()),
            'priorityReports' => $reports->priorityReports($request->user()),
            'branches' => Branch::query()->orderBy('name')->get(),
            'chartAccounts' => $this->ledgerAccountOptions($request),
            'canChooseBranch' => ApiSecurity::hasGlobalBranchAccess($request->user()),
            'defaultFrom' => today()->toDateString(),
            'defaultTo' => now()->toDateString(),
        ]);
    }

    public function show(Request $request, string $report, ReportCatalogService $reports): View
    {
        $this->authorizeReportCatalogAccess($request->user());

        $organizationId = app(TenantContext::class)->id() ?: $request->user()->organization_id;
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('organization_id', $organizationId)],
            'chart_account_id' => ['nullable', 'integer', Rule::exists('chart_accounts', 'id')->where('organization_id', $organizationId)],
            'chart_account_ids' => ['nullable', 'array'],
            'chart_account_ids.*' => ['integer', 'distinct', Rule::exists('chart_accounts', 'id')->where('organization_id', $organizationId)],
            'detail' => ['nullable', 'in:brief,detailed'],
            'interest_basis' => ['nullable', 'in:collected,projected'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        if (! ApiSecurity::hasGlobalBranchAccess($request->user()) && isset($data['branch_id'])) {
            abort_unless((int) $data['branch_id'] === (int) $request->user()->branch_id, 403);
        }

        return view('reports.show', $reports->generate($report, $data, $request->user()) + [
            'branches' => Branch::query()->orderBy('name')->get(),
            'chartAccounts' => $this->ledgerAccountOptions($request),
            'canChooseBranch' => ApiSecurity::hasGlobalBranchAccess($request->user()),
        ]);
    }

    /**
     * @return EloquentCollection<int, ChartAccount>
     */
    private function ledgerAccountOptions(Request $request): EloquentCollection
    {
        return ChartAccount::query()
            ->with('branch')
            ->where('is_active', true)
            ->whereNull('merged_into_id')
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), function (Builder $query) use ($request): void {
                $query->where(function (Builder $query) use ($request): void {
                    $query->where('branch_id', $request->user()->branch_id)->orWhereNull('branch_id');
                });
            })
            ->orderBy('code')
            ->get();
    }

    private function authorizeReportCatalogAccess(User $user): void
    {
        abort_if($user->isMember() || $user->isLoanOfficer() || $user->isLoanRecoveryOfficer(), 403);
        abort_unless($user->canPerform('view-reports'), 403);
    }
}
