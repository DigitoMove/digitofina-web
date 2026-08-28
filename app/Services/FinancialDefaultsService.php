<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\ChartAccountTemplate;
use App\Models\LoanProduct;
use App\Models\LoanProductTemplate;
use App\Models\Organization;
use DomainException;
use Illuminate\Support\Facades\DB;

class FinancialDefaultsService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function loanProductCatalog(): array
    {
        return [
            [
                'key' => 'micro-business-loan', 'name' => 'Micro Business Loan', 'code' => 'MICRO-12',
                'description' => 'Working capital loans for member businesses.', 'min_amount' => 100000, 'max_amount' => 10000000,
                'min_term' => 1, 'max_term' => 24, 'term_unit' => 'months', 'annual_interest_rate' => 24,
                'is_interest_free' => false, 'interest_method' => 'declining_balance', 'interest_frequency' => 'monthly',
                'repayment_frequency' => 'monthly', 'default_term_months' => 12, 'grace_period_days' => 0,
                'penalty_type' => 'percentage', 'penalty_rate' => 2, 'processing_fee' => 25000,
                'insurance_fee' => 0, 'application_fee' => 10000, 'required_savings_percentage' => 10,
                'require_guarantors' => true, 'minimum_guarantors' => 1, 'require_collateral' => false,
                'auto_deduct_repayment_from_savings' => false, 'loan_account_number_prefix' => 'LN',
                'is_active' => true, 'status' => 'active',
            ],
            [
                'key' => 'weekly-20-percent-loan', 'name' => 'Weekly 20% Loan', 'code' => 'WEEKLY20',
                'description' => 'Personal and SMEs Loan, with a 20% of Interest', 'min_amount' => 50000, 'max_amount' => 10000000,
                'min_term' => 1, 'max_term' => 92, 'term_unit' => 'weeks', 'annual_interest_rate' => 260,
                'is_interest_free' => false, 'interest_method' => 'flat', 'interest_frequency' => 'weekly',
                'repayment_frequency' => 'weekly', 'default_term_months' => 4, 'grace_period_days' => 0,
                'penalty_type' => 'percentage', 'penalty_rate' => 2, 'processing_fee' => 10000,
                'insurance_fee' => 0, 'application_fee' => 0, 'required_savings_percentage' => 0,
                'require_guarantors' => false, 'minimum_guarantors' => 0, 'require_collateral' => false,
                'auto_deduct_repayment_from_savings' => true, 'loan_account_number_prefix' => 'LN',
                'is_active' => true, 'status' => 'active',
            ],
            [
                'key' => 'monthly-20-percent-loan', 'name' => 'Monthly 20% Loan', 'code' => 'MONTHLY20',
                'description' => null, 'min_amount' => 50000, 'max_amount' => 10000000,
                'min_term' => 1, 'max_term' => 24, 'term_unit' => 'months', 'annual_interest_rate' => 240,
                'is_interest_free' => false, 'interest_method' => 'flat', 'interest_frequency' => 'monthly',
                'repayment_frequency' => 'monthly', 'default_term_months' => 1, 'grace_period_days' => 0,
                'penalty_type' => 'percentage', 'penalty_rate' => 2, 'processing_fee' => 0,
                'insurance_fee' => 0, 'application_fee' => 0, 'required_savings_percentage' => 0,
                'require_guarantors' => false, 'minimum_guarantors' => 0, 'require_collateral' => false,
                'auto_deduct_repayment_from_savings' => true, 'loan_account_number_prefix' => 'LN',
                'is_active' => true, 'status' => 'active',
            ],
            [
                'key' => 'weekly-15-percent-loan', 'name' => 'Weekly 15% Loan', 'code' => 'WEEKLY15',
                'description' => null, 'min_amount' => 50000, 'max_amount' => 10000000,
                'min_term' => 1, 'max_term' => 102, 'term_unit' => 'weeks', 'annual_interest_rate' => 195,
                'is_interest_free' => false, 'interest_method' => 'flat', 'interest_frequency' => 'monthly',
                'repayment_frequency' => 'monthly', 'default_term_months' => 4, 'grace_period_days' => 0,
                'penalty_type' => 'percentage', 'penalty_rate' => 2, 'processing_fee' => 0,
                'insurance_fee' => 0, 'application_fee' => 0, 'required_savings_percentage' => 0,
                'require_guarantors' => false, 'minimum_guarantors' => 0, 'require_collateral' => false,
                'auto_deduct_repayment_from_savings' => true, 'loan_account_number_prefix' => 'LN',
                'is_active' => true, 'status' => 'active',
            ],
            [
                'key' => 'monthly-15-percent-loan', 'name' => 'Monthly 15% Loan', 'code' => 'MONTHLY15',
                'description' => null, 'min_amount' => 50000, 'max_amount' => 10000000,
                'min_term' => 1, 'max_term' => 24, 'term_unit' => 'months', 'annual_interest_rate' => 180,
                'is_interest_free' => false, 'interest_method' => 'declining_balance', 'interest_frequency' => 'monthly',
                'repayment_frequency' => 'monthly', 'default_term_months' => 1, 'grace_period_days' => 0,
                'penalty_type' => 'percentage', 'penalty_rate' => 2, 'processing_fee' => 0,
                'insurance_fee' => 0, 'application_fee' => 0, 'required_savings_percentage' => 0,
                'require_guarantors' => false, 'minimum_guarantors' => 0, 'require_collateral' => false,
                'auto_deduct_repayment_from_savings' => true, 'loan_account_number_prefix' => 'LN',
                'is_active' => true, 'status' => 'active',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function chartAccountCatalog(): array
    {
        return [...$this->organizationChartAccounts(), ...$this->branchChartAccounts()];
    }

    public function syncCatalog(): void
    {
        foreach ($this->loanProductCatalog() as $definition) {
            LoanProductTemplate::query()->updateOrCreate(['key' => $definition['key']], $definition);
        }

        foreach ($this->chartAccountCatalog() as $definition) {
            ChartAccountTemplate::query()->updateOrCreate(['key' => $definition['key']], $definition);
        }
    }

    public function provisionOrganization(Organization $organization): void
    {
        $this->syncCatalog();
        $products = LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id);

        if (! $products->exists()) {
            LoanProductTemplate::query()->where('is_active', true)->orderBy('id')->each(
                function (LoanProductTemplate $template) use ($organization): void {
                    LoanProduct::withoutGlobalScope('tenant')->firstOrCreate(
                        ['organization_id' => $organization->id, 'code' => $template->code],
                        $this->loanProductAttributes($template),
                    );
                },
            );
        }

        ChartAccountTemplate::query()
            ->where('scope', 'organization')
            ->where('is_active', true)
            ->orderBy('code')
            ->each(function (ChartAccountTemplate $template) use ($organization): void {
                ChartAccount::withoutGlobalScope('tenant')->firstOrCreate(
                    ['organization_id' => $organization->id, 'code' => $template->code],
                    $this->chartAccountAttributes($template, null),
                );
            });
    }

    public function provisionBranch(Branch $branch): void
    {
        $this->syncCatalog();

        ChartAccountTemplate::query()
            ->where('scope', 'branch')
            ->where('is_active', true)
            ->orderBy('code')
            ->each(function (ChartAccountTemplate $template) use ($branch): void {
                ChartAccount::withoutGlobalScope('tenant')->firstOrCreate(
                    ['organization_id' => $branch->organization_id, 'code' => $branch->code.'-'.$template->code],
                    $this->chartAccountAttributes($template, $branch),
                );
            });
    }

    /**
     * @param  array<int, int>  $loanProductTemplateIds
     * @param  array<int, int>  $chartAccountTemplateIds
     * @return array{loan_products:int,chart_accounts:int}
     */
    public function assignSelected(Organization $organization, array $loanProductTemplateIds, array $chartAccountTemplateIds): array
    {
        $this->syncCatalog();

        return DB::transaction(function () use ($organization, $loanProductTemplateIds, $chartAccountTemplateIds): array {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();

            return [
                'loan_products' => $this->assignLoanProductTemplates($organization, $loanProductTemplateIds),
                'chart_accounts' => $this->assignChartAccountTemplates($organization, $chartAccountTemplateIds),
            ];
        });
    }

    /**
     * @return array{deleted:int,created:int,reset:int}
     */
    public function reset(Organization $organization, string $resource): array
    {
        $this->syncCatalog();

        return DB::transaction(function () use ($organization, $resource): array {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();

            return match ($resource) {
                'loan_products' => $this->resetLoanProducts($organization),
                'chart_accounts' => $this->resetChartAccounts($organization),
                default => throw new DomainException('Unsupported financial-default resource.'),
            };
        });
    }

    /**
     * @param  array<int, int>  $templateIds
     */
    private function assignLoanProductTemplates(Organization $organization, array $templateIds): int
    {
        $created = 0;

        LoanProductTemplate::query()->whereKey($templateIds)->where('is_active', true)->orderBy('id')->each(
            function (LoanProductTemplate $template) use ($organization, &$created): void {
                $product = LoanProduct::withoutGlobalScope('tenant')->firstOrCreate(
                    ['organization_id' => $organization->id, 'code' => $template->code],
                    $this->loanProductAttributes($template),
                );
                $created += $product->wasRecentlyCreated ? 1 : 0;
            },
        );

        return $created;
    }

    /**
     * @param  array<int, int>  $templateIds
     */
    private function assignChartAccountTemplates(Organization $organization, array $templateIds): int
    {
        $created = 0;
        $branches = Branch::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->get();

        ChartAccountTemplate::query()->whereKey($templateIds)->where('is_active', true)->orderBy('id')->each(
            function (ChartAccountTemplate $template) use ($organization, $branches, &$created): void {
                if ($template->scope === 'organization') {
                    $account = ChartAccount::withoutGlobalScope('tenant')->firstOrCreate(
                        ['organization_id' => $organization->id, 'code' => $template->code],
                        $this->chartAccountAttributes($template, null),
                    );
                    $created += $account->wasRecentlyCreated ? 1 : 0;

                    return;
                }

                foreach ($branches as $branch) {
                    $account = ChartAccount::withoutGlobalScope('tenant')->firstOrCreate(
                        ['organization_id' => $organization->id, 'code' => $branch->code.'-'.$template->code],
                        $this->chartAccountAttributes($template, $branch),
                    );
                    $created += $account->wasRecentlyCreated ? 1 : 0;
                }
            },
        );

        return $created;
    }

    /**
     * @return array{deleted:int,created:int,reset:int}
     */
    private function resetLoanProducts(Organization $organization): array
    {
        $products = LoanProduct::withoutGlobalScope('tenant')->where('organization_id', $organization->id);
        $referencedProducts = (clone $products)->whereHas('loans')->count();

        if ($referencedProducts > 0) {
            throw new DomainException("Reset blocked: {$referencedProducts} loan product(s) are attached to loans. Historical loan records must remain linked to their original products.");
        }

        $templates = LoanProductTemplate::query()->where('is_active', true)->orderBy('id')->get();
        $deleted = (clone $products)->whereNotIn('code', $templates->pluck('code'))->delete();
        $created = 0;
        $reset = 0;

        foreach ($templates as $template) {
            $product = LoanProduct::withoutGlobalScope('tenant')->firstOrNew([
                'organization_id' => $organization->id,
                'code' => $template->code,
            ]);
            $wasNew = ! $product->exists;
            $product->fill($this->loanProductAttributes($template))->save();
            $wasNew ? $created++ : $reset++;
        }

        return ['deleted' => $deleted, 'created' => $created, 'reset' => $reset];
    }

    /**
     * @return array{deleted:int,created:int,reset:int}
     */
    private function resetChartAccounts(Organization $organization): array
    {
        $accounts = ChartAccount::withoutGlobalScope('tenant')->where('organization_id', $organization->id);
        $referencedAccounts = (clone $accounts)
            ->where(function ($query): void {
                $query->whereHas('journalEntries')
                    ->orWhereHas('journalLines')
                    ->orWhereHas('dailyAccountingSummaries')
                    ->orWhereHas('children')
                    ->orWhereHas('mergedSources')
                    ->orWhereNotNull('merged_into_id');
            })
            ->count();

        if ($referencedAccounts > 0) {
            throw new DomainException("Reset blocked: {$referencedAccounts} chart account(s) have accounting or merge history. Financial history cannot be deleted.");
        }

        $templates = ChartAccountTemplate::query()->where('is_active', true)->orderBy('id')->get();
        $branches = Branch::withoutGlobalScope('tenant')->where('organization_id', $organization->id)->get();
        $expectedCodes = $templates->flatMap(function (ChartAccountTemplate $template) use ($branches): array {
            if ($template->scope === 'organization') {
                return [$template->code];
            }

            return $branches->map(fn (Branch $branch): string => $branch->code.'-'.$template->code)->all();
        });
        $deleted = (clone $accounts)->whereNotIn('code', $expectedCodes)->delete();
        $created = 0;
        $reset = 0;

        foreach ($templates as $template) {
            $targets = $template->scope === 'organization' ? collect([null]) : $branches;

            foreach ($targets as $branch) {
                $account = ChartAccount::withoutGlobalScope('tenant')->firstOrNew([
                    'organization_id' => $organization->id,
                    'code' => $branch ? $branch->code.'-'.$template->code : $template->code,
                ]);
                $wasNew = ! $account->exists;
                $account->fill($this->chartAccountAttributes($template, $branch))->save();
                $wasNew ? $created++ : $reset++;
            }
        }

        return ['deleted' => $deleted, 'created' => $created, 'reset' => $reset];
    }

    /**
     * @return array<string, mixed>
     */
    private function loanProductAttributes(LoanProductTemplate $template): array
    {
        return $template->only([
            'name', 'description', 'min_amount', 'max_amount', 'min_term', 'max_term', 'term_unit',
            'annual_interest_rate', 'is_interest_free', 'interest_method', 'interest_frequency',
            'repayment_frequency', 'default_term_months', 'grace_period_days', 'penalty_type', 'penalty_rate',
            'processing_fee', 'insurance_fee', 'application_fee', 'required_savings_percentage',
            'require_guarantors', 'minimum_guarantors', 'require_collateral',
            'auto_deduct_repayment_from_savings', 'loan_account_number_prefix', 'is_active', 'status',
        ]) + ['loan_product_template_id' => $template->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function chartAccountAttributes(ChartAccountTemplate $template, ?Branch $branch): array
    {
        return $template->only([
            'name', 'type', 'normal_balance', 'drawer_direction', 'is_cash_account', 'is_system', 'is_active',
        ]) + [
            'chart_account_template_id' => $template->id,
            'branch_id' => $branch?->id,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function organizationChartAccounts(): array
    {
        return [
            $this->chart('organization-1000', 'organization', '1000', 'Cash on Hand', 'asset', 'debit', true, true),
            $this->chart('organization-1010', 'organization', '1010', 'Cash in Teller Drawers', 'asset', 'debit', true, true),
            $this->chart('organization-1020', 'organization', '1020', 'Cash in Vault', 'asset', 'debit', true, true),
            $this->chart('organization-1100', 'organization', '1100', 'Bank Current Account', 'asset', 'debit', false, true),
            $this->chart('organization-1200', 'organization', '1200', 'Loans Receivable', 'asset', 'debit', false, true),
            $this->chart('organization-1300', 'organization', '1300', 'Interest Receivable', 'asset', 'debit', false, true),
            $this->chart('organization-2100', 'organization', '2100', 'Client Savings Liability', 'liability', 'credit', false, true),
            $this->chart('organization-2200', 'organization', '2200', 'Client Fixed Deposit Liability', 'liability', 'credit', false, true),
            $this->chart('organization-2300', 'organization', '2300', 'Charges Payable', 'liability', 'credit', false, true),
            $this->chart('organization-3000', 'organization', '3000', 'Member Shares / Equity', 'equity', 'credit', false, true),
            $this->chart('organization-4000', 'organization', '4000', 'Operating Income', 'income', 'credit', false, true),
            $this->chart('organization-4100', 'organization', '4100', 'Interest Income', 'income', 'credit', false, true),
            $this->chart('organization-4200', 'organization', '4200', 'Fees and Penalties Income', 'income', 'credit', false, true),
            $this->chart('organization-5000', 'organization', '5000', 'Operating Expenses', 'expense', 'debit', false, true),
            $this->chart('organization-5100', 'organization', '5100', 'Loan Loss Provision Expense', 'expense', 'debit', false, true),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function branchChartAccounts(): array
    {
        return [
            $this->chart('branch-5200', 'branch', '5200', 'Welfare Expense', 'expense', 'debit'),
            $this->chart('branch-5210', 'branch', '5210', 'Transport Expense', 'expense', 'debit'),
            $this->chart('branch-5220', 'branch', '5220', 'Travel Expense', 'expense', 'debit'),
            $this->chart('branch-5230', 'branch', '5230', 'Office Supplies Expense', 'expense', 'debit'),
            $this->chart('branch-5290', 'branch', '5290', 'Other Expenses', 'expense', 'debit'),
            $this->chart('branch-5295', 'branch', '5295', 'Transaction Charges Expense', 'expense', 'debit', false, true, 'cash_out'),
            $this->chart('branch-4300', 'branch', '4300', 'Other Income', 'income', 'credit'),
            $this->chart('branch-4310', 'branch', '4310', 'Welfare Income', 'income', 'credit'),
            $this->chart('branch-4320', 'branch', '4320', 'Other Business Income', 'income', 'credit'),
            $this->chart('branch-4330', 'branch', '4330', 'Application Fees Income', 'income', 'credit'),
            $this->chart('branch-3990', 'branch', '3990', 'Opening Balance Equity', 'equity', 'credit', false, true, 'none'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chart(
        string $key,
        string $scope,
        string $code,
        string $name,
        string $type,
        string $normalBalance,
        bool $isCashAccount = false,
        bool $isSystem = false,
        ?string $drawerDirection = null,
    ): array {
        return [
            'key' => $key,
            'scope' => $scope,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'normal_balance' => $normalBalance,
            'drawer_direction' => $drawerDirection,
            'is_cash_account' => $isCashAccount,
            'is_system' => $isSystem,
            'is_active' => true,
        ];
    }
}
