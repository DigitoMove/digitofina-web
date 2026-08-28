<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignFinancialDefaultsRequest;
use App\Http\Requests\ResetFinancialDefaultsRequest;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\ChartAccountTemplate;
use App\Models\LoanProduct;
use App\Models\LoanProductTemplate;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\FinancialDefaultsService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationFinancialDefaultsController extends Controller
{
    public function index(Request $request, Organization $organization, FinancialDefaultsService $financialDefaults): View
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);
        $financialDefaults->syncCatalog();

        $branches = Branch::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->orderBy('name')->get();
        $loanProductCodes = LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->pluck('code');
        $chartAccountCodes = ChartAccount::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->pluck('code');

        $loanProductTemplates = LoanProductTemplate::query()->where('is_active', true)->orderBy('name')->get()
            ->each(fn (LoanProductTemplate $template) => $template->setAttribute('is_assigned', $loanProductCodes->contains($template->code)));
        $chartAccountTemplates = ChartAccountTemplate::query()->where('is_active', true)->orderBy('scope')->orderBy('code')->get()
            ->each(function (ChartAccountTemplate $template) use ($branches, $chartAccountCodes): void {
                $targetCodes = $template->scope === 'organization'
                    ? collect([$template->code])
                    : $branches->map(fn (Branch $branch): string => $branch->code.'-'.$template->code);
                $template->setAttribute('assigned_instances', $targetCodes->intersect($chartAccountCodes)->count());
                $template->setAttribute('target_instances', $targetCodes->count());
            });

        return view('organizations.financial-defaults', [
            'organization' => $organization,
            'branches' => $branches,
            'loanProductTemplates' => $loanProductTemplates,
            'chartAccountTemplates' => $chartAccountTemplates,
            'loanProductCount' => $loanProductCodes->count(),
            'chartAccountCount' => $chartAccountCodes->count(),
        ]);
    }

    public function store(
        AssignFinancialDefaultsRequest $request,
        Organization $organization,
        FinancialDefaultsService $financialDefaults,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $data = $request->validated();
        $result = $financialDefaults->assignSelected(
            $organization,
            $data['loan_product_template_ids'] ?? [],
            $data['chart_account_template_ids'] ?? [],
        );
        $auditLogger->record('organization.financial_defaults_assigned', $organization, [
            'target_organization_id' => $organization->id,
            'loan_product_template_ids' => $data['loan_product_template_ids'] ?? [],
            'chart_account_template_ids' => $data['chart_account_template_ids'] ?? [],
            'created' => $result,
        ], actor: $request->user());

        return back()->with('status', "Assigned {$result['loan_products']} loan product(s) and {$result['chart_accounts']} chart account(s). Existing records were left unchanged.");
    }

    public function destroy(
        ResetFinancialDefaultsRequest $request,
        Organization $organization,
        FinancialDefaultsService $financialDefaults,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $resource = $request->validated('resource');

        try {
            $result = $financialDefaults->reset($organization, $resource);
        } catch (DomainException $exception) {
            return back()->withErrors(['financial_defaults' => $exception->getMessage()]);
        }

        $auditLogger->record('organization.financial_defaults_reset', $organization, [
            'target_organization_id' => $organization->id,
            'resource' => $resource,
            'result' => $result,
        ], actor: $request->user());

        return back()->with('status', "Reset complete: {$result['reset']} restored, {$result['created']} created, and {$result['deleted']} custom record(s) removed.");
    }
}
