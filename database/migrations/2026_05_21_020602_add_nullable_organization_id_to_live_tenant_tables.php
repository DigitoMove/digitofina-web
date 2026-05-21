<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that may hold organization-owned records in live deployments.
     *
     * @var array<int, string>
     */
    private array $tenantTables = [
        'users',
        'branches',
        'clients',
        'client_documents',
        'client_notes',
        'client_groups',
        'client_group_members',
        'loan_products',
        'loans',
        'loan_applications',
        'loan_schedules',
        'loan_repayments',
        'loan_repayment_allocations',
        'loan_approvals',
        'loan_penalties',
        'loan_disbursements',
        'guarantors',
        'collaterals',
        'savings_products',
        'savings_accounts',
        'savings_transactions',
        'savings_goals',
        'savings_goal_transactions',
        'fixed_savings_accounts',
        'group_savings_accounts',
        'group_savings_contributions',
        'investment_plans',
        'investor_accounts',
        'investment_accounts',
        'investment_transactions',
        'investment_returns',
        'share_products',
        'share_accounts',
        'share_transactions',
        'fixed_deposit_products',
        'fixed_deposit_accounts',
        'fixed_deposit_transactions',
        'chart_accounts',
        'journal_entries',
        'journal_lines',
        'accounts',
        'transactions',
        'sms_logs',
        'sms_providers',
        'audit_logs',
        'system_settings',
        'document_records',
        'report_exports',
        'drawer_sessions',
        'drawer_movements',
        'teller_drawers',
        'mobile_money_transactions',
        'payment_requests',
        'payment_callbacks',
        'payment_providers',
        'maker_checker_requests',
        'role_financial_limits',
        'branch_cash_reconciliations',
        'branch_daily_summaries',
        'daily_accounting_summaries',
        'import_batches',
        'import_rows',
        'offline_sync_batches',
        'offline_sync_records',
        'webhook_events',
        'webhook_providers',
        'idempotency_keys',
        'device_tokens',
        'locked_funds',
        'loan_securities',
    ];

    public function up(): void
    {
        foreach ($this->tenantTables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'organization_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $column = $table->foreignId('organization_id')->nullable();

                if (Schema::hasColumn($tableName, 'id')) {
                    $column->after('id');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tenantTables) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'organization_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('organization_id');
            });
        }
    }
};
