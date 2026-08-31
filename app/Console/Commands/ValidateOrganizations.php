<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ValidateOrganizations extends Command
{
    protected $signature = 'gateoffina:validate-organizations';

    protected $description = 'Validate organization_id coverage and tenant consistency before enforcing non-null constraints';

    /**
     * @var array<int, string>
     */
    private array $requiredTables = [
        'users',
        'branches',
        'clients',
        'client_documents',
        'loan_products',
        'loans',
        'loan_schedules',
        'loan_repayments',
        'loan_disbursements',
        'guarantors',
        'collaterals',
        'savings_products',
        'savings_accounts',
        'savings_transactions',
        'investment_plans',
        'investor_accounts',
        'investment_accounts',
        'investment_transactions',
        'journal_entries',
        'journal_lines',
        'sms_logs',
        'audit_logs',
        'system_settings',
        'document_records',
        'report_exports',
        'drawer_sessions',
        'mobile_money_transactions',
    ];

    public function handle(): int
    {
        $issues = [];

        foreach ($this->requiredTables as $table) {
            if (! $this->hasOrganizationColumn($table)) {
                continue;
            }

            $count = DB::table($table)->whereNull('organization_id')->count();

            if ($count > 0) {
                $issues[] = [$table, 'null organization_id', $count];
            }
        }

        $this->relationshipCheck($issues, 'loans', 'clients', 'client_id', 'Loans whose organization does not match the client');
        $this->relationshipCheck($issues, 'loan_repayments', 'loans', 'loan_id', 'Repayments whose organization does not match the loan');
        $this->relationshipCheck($issues, 'loan_disbursements', 'loans', 'loan_id', 'Disbursements whose organization does not match the loan');
        $this->relationshipCheck($issues, 'loan_schedules', 'loans', 'loan_id', 'Loan schedules whose organization does not match the loan');
        $this->relationshipCheck($issues, 'guarantors', 'loans', 'loan_id', 'Guarantors whose organization does not match the loan');
        $this->relationshipCheck($issues, 'collaterals', 'loans', 'loan_id', 'Collaterals whose organization does not match the loan');
        $this->relationshipCheck($issues, 'savings_transactions', 'savings_accounts', 'savings_account_id', 'Savings transactions whose organization does not match the savings account');
        $this->relationshipCheck($issues, 'journal_lines', 'journal_entries', 'journal_entry_id', 'Journal lines whose organization does not match the journal entry');
        $this->relationshipCheck($issues, 'client_documents', 'clients', 'client_id', 'Documents whose organization does not match the client');

        if ($issues === []) {
            $this->info('Organization validation passed. No null organization_id values or relationship mismatches were found.');

            return self::SUCCESS;
        }

        $this->table(['Table', 'Issue', 'Count'], $issues);
        $this->error('Organization validation failed. Fix these records before making organization_id non-nullable.');

        return self::FAILURE;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: int}>  $issues
     */
    private function relationshipCheck(array &$issues, string $childTable, string $parentTable, string $foreignKey, string $label): void
    {
        if (
            ! $this->hasOrganizationColumn($childTable)
            || ! $this->hasOrganizationColumn($parentTable)
            || ! Schema::hasColumn($childTable, $foreignKey)
        ) {
            return;
        }

        $count = DB::table($childTable)
            ->join($parentTable, "{$parentTable}.id", '=', "{$childTable}.{$foreignKey}")
            ->whereNotNull("{$childTable}.organization_id")
            ->whereNotNull("{$parentTable}.organization_id")
            ->whereColumn("{$childTable}.organization_id", '!=', "{$parentTable}.organization_id")
            ->count();

        if ($count > 0) {
            $issues[] = [$childTable, $label, $count];
        }
    }

    private function hasOrganizationColumn(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'organization_id');
    }
}
