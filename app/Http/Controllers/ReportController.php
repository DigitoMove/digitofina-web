<?php

namespace App\Http\Controllers;

use App\CurrencyRateService;
use App\CurrencyRegistry;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Organization;
use App\Models\User;
use App\Services\ReportCatalogService;
use App\Services\TenantContext;
use App\Support\ApiSecurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request, ReportCatalogService $reports): View
    {
        $this->authorizeReportCatalogAccess($request->user());
        $organizationId = $this->activeOrganizationId($request);

        return view('reports.index', [
            'groups' => $reports->grouped($request->user()),
            'priorityReports' => $reports->priorityReports($request->user()),
            'branches' => Branch::query()->where('organization_id', $organizationId)->orderBy('name')->get(),
            'organization' => Organization::query()->find($organizationId),
            'staffUsers' => User::query()->where('organization_id', $organizationId)->where('status', 'active')->where('is_active', true)->whereHas('role', fn (Builder $query): Builder => $query->where('slug', '!=', 'member'))->with('role')->orderBy('name')->get(),
            'chartAccounts' => $this->ledgerAccountOptions($request, $organizationId),
            'canChooseBranch' => ApiSecurity::hasGlobalBranchAccess($request->user()),
            'currencies' => CurrencyRegistry::all(),
            'defaultFrom' => today()->toDateString(),
            'defaultTo' => now()->toDateString(),
        ]);
    }

    public function show(Request $request, string $report, ReportCatalogService $reports, CurrencyRateService $currencyRates): View
    {
        $this->authorizeReportCatalogAccess($request->user());

        $organizationId = $this->activeOrganizationId($request);
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
            'posted_by_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $organizationId)],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(array_keys(CurrencyRegistry::all()))],
        ]);

        $baseCurrency = strtoupper((string) (app(TenantContext::class)->organization()?->currency ?: $request->user()->organization?->currency ?: 'UGX'));
        $targetCurrency = strtoupper((string) ($data['currency'] ?? $baseCurrency));
        if ($targetCurrency !== $baseCurrency) {
            try {
                $currencyRates->rate($baseCurrency, $targetCurrency);
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
            }
        }

        if (! ApiSecurity::hasGlobalBranchAccess($request->user()) && isset($data['branch_id'])) {
            abort_unless((int) $data['branch_id'] === (int) $request->user()->branch_id, 403);
        }

        return view('reports.show', $reports->generate($report, $data, $request->user()) + [
            'branches' => Branch::query()->where('organization_id', $organizationId)->orderBy('name')->get(),
            'organization' => Organization::query()->find($organizationId),
            'staffUsers' => User::query()->where('organization_id', $organizationId)->where('status', 'active')->where('is_active', true)->whereHas('role', fn (Builder $query): Builder => $query->where('slug', '!=', 'member'))->with('role')->orderBy('name')->get(),
            'chartAccounts' => $this->ledgerAccountOptions($request, $organizationId),
            'canChooseBranch' => ApiSecurity::hasGlobalBranchAccess($request->user()),
            'currencies' => CurrencyRegistry::all(),
        ]);
    }

    /**
     * @return EloquentCollection<int, ChartAccount>
     */
    private function ledgerAccountOptions(Request $request, int $organizationId): EloquentCollection
    {
        return ChartAccount::query()
            ->with('branch')
            ->where('organization_id', $organizationId)
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

    private function activeOrganizationId(Request $request): int
    {
        $organizationId = app(TenantContext::class)->id() ?: $request->user()->organization_id;

        abort_unless($organizationId, 403, 'Select an organization before opening reports.');

        return (int) $organizationId;
    }

    private function authorizeReportCatalogAccess(User $user): void
    {
        abort_if($user->isMember() || $user->isLoanOfficer() || $user->isLoanRecoveryOfficer(), 403);
        abort_unless($user->canPerform('view-reports'), 403);
    }
}
