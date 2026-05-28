<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class DataWipeService
{
    /**
     * @return array<string, array{label: string, description: string}>
     */
    public function categories(): array
    {
        return [
            'clients' => [
                'label' => 'Clients and groups',
                'description' => 'Client records, notes, documents, groups, relationships, and records that depend on those clients.',
            ],
            'accounts' => [
                'label' => 'Accounts and savings',
                'description' => 'Client accounts, savings records, fixed savings, group savings, shares, and dependent transactions.',
            ],
            'loans' => [
                'label' => 'Loans',
                'description' => 'Loans, schedules, repayments, approvals, collateral, guarantors, securities, and dependent accounting rows.',
            ],
            'investments' => [
                'label' => 'Investments',
                'description' => 'Investment plans, investor accounts, investment accounts, transactions, returns, and locked funds.',
            ],
            'teller' => [
                'label' => 'Teller and drawer activity',
                'description' => 'Drawers, drawer sessions, drawer movements, denominations, and related cash postings.',
            ],
            'accounting' => [
                'label' => 'Accounting and ledger',
                'description' => 'Transactions, journal entries, journal lines, chart accounts, summaries, financial days, and reconciliations.',
            ],
            'imports_sync' => [
                'label' => 'Imports and offline sync',
                'description' => 'Import batches, import rows, offline sync batches, offline records, and idempotency records.',
            ],
            'communications' => [
                'label' => 'Communications, audit, webhooks, and reports',
                'description' => 'Notifications, SMS logs, payment callbacks, webhook events, report exports, alerts, and audit logs.',
            ],
            'access_setup' => [
                'label' => 'Staff, access, and setup records',
                'description' => 'Staff users, roles, permissions, module settings, organization settings, domains, providers, and branch containers.',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function defaultClearCategories(): array
    {
        return array_values(array_diff(array_keys($this->categories()), ['access_setup']));
    }

    /**
     * @param  array<int, string>  $categories
     * @return array{context: array<string, mixed>, categories: array<int, string>, tables: array<string, array{label: string, category: string, count: int}>, total: int}
     */
    public function preview(string $scopeType, int $targetId, array $categories, User $actor): array
    {
        $context = $this->context($scopeType, $targetId, $actor);
        $categories = $this->normalizeCategories($categories);
        $ids = $this->resolveIds($context, $categories, $actor);
        $tables = [];
        $total = 0;

        foreach ($this->definitions() as $table => $definition) {
            if (! $this->tableExists($table)) {
                continue;
            }

            $count = $this->deleteQuery($table, $definition, $ids)->count();

            if ($count === 0) {
                continue;
            }

            $tables[$table] = [
                'label' => $definition['label'],
                'category' => $this->categories()[$definition['category']]['label'] ?? str($definition['category'])->headline()->toString(),
                'count' => $count,
            ];
            $total += $count;
        }

        return [
            'context' => $context,
            'categories' => $categories,
            'tables' => $tables,
            'total' => $total,
        ];
    }

    /**
     * @param  array<int, string>  $categories
     * @return array{context: array<string, mixed>, categories: array<int, string>, tables: array<string, array{label: string, category: string, count: int}>, total: int}
     */
    public function wipe(string $scopeType, int $targetId, array $categories, User $actor): array
    {
        $preview = $this->preview($scopeType, $targetId, $categories, $actor);

        return DB::transaction(function () use ($preview): array {
            $ids = $this->resolveIds($preview['context'], $preview['categories'], auth()->user());

            foreach ($this->deleteOrder($ids) as $table => $definition) {
                $this->deleteQuery($table, $definition, $ids)->delete();
            }

            return $preview;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function context(string $scopeType, int $targetId, User $actor): array
    {
        if ($scopeType === 'organization') {
            $organization = Organization::query()->findOrFail($targetId);
            abort_unless($actor->hasRole('super-admin') || (int) $actor->organization_id === (int) $organization->id, 403);

            return [
                'type' => 'organization',
                'organization_id' => $organization->id,
                'branch_id' => null,
                'user_id' => null,
                'label' => $organization->name,
            ];
        }

        if ($scopeType === 'branch') {
            $branch = Branch::query()->with('organization')->findOrFail($targetId);
            abort_unless($actor->canAccessBranch($branch->id, $branch->organization_id), 403);

            return [
                'type' => 'branch',
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->id,
                'user_id' => null,
                'label' => $branch->name,
            ];
        }

        if ($scopeType === 'user') {
            $targetUser = User::query()->with('organization')->findOrFail($targetId);
            abort_if((int) $targetUser->id === (int) $actor->id, 422, 'You cannot wipe your own user record or entries while signed in.');
            abort_unless($actor->hasRole('super-admin') || (int) $actor->organization_id === (int) $targetUser->organization_id, 403);

            return [
                'type' => 'user',
                'organization_id' => $targetUser->organization_id,
                'branch_id' => $targetUser->branch_id,
                'user_id' => $targetUser->id,
                'label' => $targetUser->name,
            ];
        }

        throw new InvalidArgumentException('Select an organization, branch, or user to wipe.');
    }

    /**
     * @param  array<int, string>  $categories
     * @return array<int, string>
     */
    private function normalizeCategories(array $categories): array
    {
        $valid = array_keys($this->categories());
        $categories = array_values(array_intersect($valid, $categories));

        if ($categories === []) {
            throw new InvalidArgumentException('Select at least one data category to wipe.');
        }

        return $categories;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, string>  $categories
     * @return array<string, Collection<int, int|string>>
     */
    private function resolveIds(array $context, array $categories, ?User $actor): array
    {
        $ids = [];

        foreach ($this->definitions() as $table => $definition) {
            if (! $this->tableExists($table) || ! $this->hasColumn($table, 'id')) {
                continue;
            }

            $query = DB::table($table);

            if ($this->definitionInitiallySelected($definition, $categories)) {
                $this->applyInitialScope($query, $table, $definition, $context, $actor);
            } else {
                $query->whereRaw('1 = 0');
            }

            $ids[$table] = $query->pluck('id')->values();
        }

        do {
            $changed = false;

            foreach ($this->definitions() as $table => $definition) {
                if (! $this->tableExists($table) || ! $this->hasColumn($table, 'id')) {
                    continue;
                }

                $query = DB::table($table)->where(function (Builder $query) use ($table, $definition, $ids): void {
                    $hasReference = false;

                    foreach ($definition['refs'] ?? [] as $column => $parentTable) {
                        if (! $this->hasColumn($table, $column) || ! isset($ids[$parentTable]) || $ids[$parentTable]->isEmpty()) {
                            continue;
                        }

                        $hasReference = true;
                        $query->orWhereIn($column, $ids[$parentTable]->all());
                    }

                    if (! $hasReference) {
                        $query->whereRaw('1 = 0');
                    }
                });

                $found = $query->pluck('id');
                $merged = ($ids[$table] ?? collect())->merge($found)->unique()->values();

                if ($merged->count() !== ($ids[$table] ?? collect())->count()) {
                    $ids[$table] = $merged;
                    $changed = true;
                }
            }
        } while ($changed);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $categories
     */
    private function definitionInitiallySelected(array $definition, array $categories): bool
    {
        return in_array($definition['category'], $categories, true);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $context
     */
    private function applyInitialScope(Builder $query, string $table, array $definition, array $context, ?User $actor): void
    {
        $query->where(function (Builder $query) use ($table, $definition, $context, $actor): void {
            $hasCondition = false;

            if ($context['type'] === 'organization' && $this->hasColumn($table, 'organization_id')) {
                $hasCondition = true;
                $query->orWhere('organization_id', $context['organization_id']);
            }

            if ($context['type'] === 'branch' && $this->hasColumn($table, 'branch_id')) {
                $hasCondition = true;
                $query->orWhere('branch_id', $context['branch_id']);
            }

            if ($context['type'] === 'user') {
                foreach ($definition['user_columns'] ?? [] as $column) {
                    if (! $this->hasColumn($table, $column)) {
                        continue;
                    }

                    $hasCondition = true;
                    $query->orWhere($column, $context['user_id']);
                }
            }

            if ($table === 'branches' && $context['type'] === 'branch') {
                $hasCondition = true;
                $query->orWhere('id', $context['branch_id']);
            }

            if ($table === 'users' && $context['type'] === 'user') {
                $hasCondition = true;
                $query->orWhere('id', $context['user_id']);
            }

            if ($table === 'users' && $actor) {
                $query->where('id', '!=', $actor->id);
            }

            if (! $hasCondition) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, Collection<int, int|string>>  $ids
     */
    private function deleteQuery(string $table, array $definition, array $ids): Builder
    {
        $query = DB::table($table);

        if ($this->hasColumn($table, 'id') && isset($ids[$table]) && $ids[$table]->isNotEmpty()) {
            return $query->whereIn('id', $ids[$table]->all());
        }

        $query->where(function (Builder $query) use ($table, $definition, $ids): void {
            $hasCondition = false;

            foreach ($definition['refs'] ?? [] as $column => $parentTable) {
                if (! $this->hasColumn($table, $column) || ! isset($ids[$parentTable]) || $ids[$parentTable]->isEmpty()) {
                    continue;
                }

                $hasCondition = true;
                $query->orWhereIn($column, $ids[$parentTable]->all());
            }

            if (! $hasCondition) {
                $query->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    /**
     * @param  array<string, Collection<int, int|string>>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function deleteOrder(array $ids): array
    {
        $definitions = array_filter(
            $this->definitions(),
            fn (array $definition, string $table): bool => $this->tableExists($table) && $this->deleteQuery($table, $definition, $ids)->count() > 0,
            ARRAY_FILTER_USE_BOTH,
        );

        $ordered = [];
        $visited = [];

        $visit = function (string $table) use (&$visit, &$ordered, &$visited, $definitions): void {
            if (($visited[$table] ?? false) || ! isset($definitions[$table])) {
                return;
            }

            $visited[$table] = true;

            foreach ($definitions as $childTable => $childDefinition) {
                if (in_array($table, $childDefinition['refs'] ?? [], true)) {
                    $visit($childTable);
                }
            }

            $ordered[$table] = $definitions[$table];
        };

        foreach (array_keys($definitions) as $table) {
            $visit($table);
        }

        return $ordered;
    }

    /**
     * @return array<string, array{label: string, category: string, user_columns?: array<int, string>, refs?: array<string, string>}>
     */
    private function definitions(): array
    {
        return [
            'notifications' => ['label' => 'Notifications', 'category' => 'communications', 'user_columns' => ['notifiable_id']],
            'sms_logs' => ['label' => 'SMS logs', 'category' => 'communications', 'refs' => ['provider_id' => 'sms_providers']],
            'suspicious_transaction_alerts' => ['label' => 'Suspicious transaction alerts', 'category' => 'communications', 'refs' => ['transaction_id' => 'transactions']],
            'audit_logs' => ['label' => 'Audit logs', 'category' => 'communications', 'user_columns' => ['user_id'], 'refs' => ['user_id' => 'users']],
            'report_exports' => ['label' => 'Report exports', 'category' => 'communications', 'user_columns' => ['created_by'], 'refs' => ['created_by' => 'users']],
            'webhook_events' => ['label' => 'Webhook events', 'category' => 'communications', 'refs' => ['webhook_provider_id' => 'webhook_providers']],
            'payment_callbacks' => ['label' => 'Payment callbacks', 'category' => 'communications', 'refs' => ['payment_provider_id' => 'payment_providers', 'payment_request_id' => 'payment_requests']],
            'payment_requests' => ['label' => 'Payment requests', 'category' => 'communications', 'refs' => ['payment_provider_id' => 'payment_providers']],
            'mobile_money_transactions' => ['label' => 'Mobile money transactions', 'category' => 'communications', 'refs' => ['payment_provider_id' => 'payment_providers']],

            'loan_repayment_allocations' => ['label' => 'Loan repayment allocations', 'category' => 'loans', 'refs' => ['loan_repayment_id' => 'loan_repayments', 'loan_schedule_id' => 'loan_schedules']],
            'loan_penalties' => ['label' => 'Loan penalties', 'category' => 'loans', 'refs' => ['loan_id' => 'loans', 'loan_schedule_id' => 'loan_schedules']],
            'loan_write_offs' => ['label' => 'Loan write offs', 'category' => 'loans', 'user_columns' => ['approved_by'], 'refs' => ['loan_id' => 'loans', 'approved_by' => 'users']],
            'loan_restructures' => ['label' => 'Loan restructures', 'category' => 'loans', 'user_columns' => ['approved_by'], 'refs' => ['loan_id' => 'loans', 'approved_by' => 'users']],
            'loan_securities' => ['label' => 'Loan securities', 'category' => 'loans', 'user_columns' => ['created_by', 'released_by'], 'refs' => ['loan_id' => 'loans', 'client_id' => 'clients', 'savings_account_id' => 'savings_accounts', 'investment_account_id' => 'investment_accounts', 'created_by' => 'users', 'released_by' => 'users']],
            'loan_approvals' => ['label' => 'Loan approvals', 'category' => 'loans', 'user_columns' => ['approver_id'], 'refs' => ['loan_id' => 'loans', 'approver_id' => 'users']],
            'loan_disbursements' => ['label' => 'Loan disbursements', 'category' => 'loans', 'user_columns' => ['disbursed_by'], 'refs' => ['loan_id' => 'loans', 'disbursed_by' => 'users']],
            'loan_repayments' => ['label' => 'Loan repayments', 'category' => 'loans', 'user_columns' => ['received_by'], 'refs' => ['loan_id' => 'loans', 'transaction_id' => 'transactions', 'received_by' => 'users']],
            'loan_schedules' => ['label' => 'Loan schedules', 'category' => 'loans', 'refs' => ['loan_id' => 'loans']],
            'guarantors' => ['label' => 'Guarantors', 'category' => 'loans', 'refs' => ['loan_id' => 'loans', 'guarantor_client_id' => 'clients']],
            'collaterals' => ['label' => 'Collateral', 'category' => 'loans', 'user_columns' => ['valued_by', 'verified_by'], 'refs' => ['client_id' => 'clients', 'loan_id' => 'loans', 'valued_by' => 'users', 'verified_by' => 'users']],
            'loans' => ['label' => 'Loans', 'category' => 'loans', 'user_columns' => ['officer_id', 'created_by', 'assigned_officer_id'], 'refs' => ['client_id' => 'clients', 'loan_account_id' => 'accounts', 'created_by' => 'users', 'assigned_officer_id' => 'users']],
            'loan_products' => ['label' => 'Loan products', 'category' => 'loans'],

            'investment_returns' => ['label' => 'Investment returns', 'category' => 'investments', 'user_columns' => ['posted_by'], 'refs' => ['investment_account_id' => 'investment_accounts', 'client_id' => 'clients', 'posted_by' => 'users']],
            'investment_transactions' => ['label' => 'Investment transactions', 'category' => 'investments', 'user_columns' => ['created_by', 'approved_by'], 'refs' => ['investor_account_id' => 'investor_accounts', 'investment_account_id' => 'investment_accounts', 'created_by' => 'users', 'approved_by' => 'users']],
            'locked_funds' => ['label' => 'Locked funds', 'category' => 'investments', 'user_columns' => ['locked_by', 'released_by'], 'refs' => ['client_id' => 'clients', 'locked_by' => 'users', 'released_by' => 'users']],
            'investment_accounts' => ['label' => 'Investment accounts', 'category' => 'investments', 'user_columns' => ['created_by', 'approved_by', 'updated_by'], 'refs' => ['investor_account_id' => 'investor_accounts', 'client_id' => 'clients', 'investment_plan_id' => 'investment_plans', 'created_by' => 'users', 'approved_by' => 'users', 'updated_by' => 'users']],
            'investor_accounts' => ['label' => 'Investor accounts', 'category' => 'investments', 'user_columns' => ['assigned_officer_id', 'created_by'], 'refs' => ['client_id' => 'clients', 'assigned_officer_id' => 'users', 'created_by' => 'users']],
            'investment_plans' => ['label' => 'Investment plans', 'category' => 'investments', 'user_columns' => ['created_by', 'updated_by'], 'refs' => ['created_by' => 'users', 'updated_by' => 'users']],

            'fixed_deposit_transactions' => ['label' => 'Fixed deposit transactions', 'category' => 'accounts', 'user_columns' => ['created_by'], 'refs' => ['fixed_deposit_account_id' => 'fixed_deposit_accounts', 'created_by' => 'users']],
            'fixed_savings_accounts' => ['label' => 'Fixed savings accounts', 'category' => 'accounts', 'user_columns' => ['created_by'], 'refs' => ['client_id' => 'clients', 'savings_account_id' => 'savings_accounts', 'created_by' => 'users']],
            'fixed_deposit_accounts' => ['label' => 'Fixed deposit accounts', 'category' => 'accounts', 'refs' => ['client_id' => 'clients', 'fixed_deposit_product_id' => 'fixed_deposit_products']],
            'fixed_deposit_products' => ['label' => 'Fixed deposit products', 'category' => 'accounts'],
            'group_savings_contributions' => ['label' => 'Group savings contributions', 'category' => 'accounts', 'user_columns' => ['created_by'], 'refs' => ['group_savings_account_id' => 'group_savings_accounts', 'client_id' => 'clients', 'created_by' => 'users']],
            'group_savings_accounts' => ['label' => 'Group savings accounts', 'category' => 'accounts', 'user_columns' => ['created_by'], 'refs' => ['client_group_id' => 'client_groups', 'savings_account_id' => 'savings_accounts', 'created_by' => 'users']],
            'savings_goal_transactions' => ['label' => 'Savings goal transactions', 'category' => 'accounts', 'user_columns' => ['created_by'], 'refs' => ['savings_goal_id' => 'savings_goals', 'savings_account_id' => 'savings_accounts', 'created_by' => 'users']],
            'savings_goals' => ['label' => 'Savings goals', 'category' => 'accounts', 'user_columns' => ['created_by'], 'refs' => ['client_id' => 'clients', 'savings_account_id' => 'savings_accounts', 'created_by' => 'users']],
            'savings_transactions' => ['label' => 'Savings transactions', 'category' => 'accounts', 'user_columns' => ['teller_id', 'reversed_by'], 'refs' => ['client_id' => 'clients', 'savings_account_id' => 'savings_accounts', 'teller_id' => 'users', 'reversed_by' => 'users']],
            'savings_accounts' => ['label' => 'Savings accounts', 'category' => 'accounts', 'user_columns' => ['created_by'], 'refs' => ['client_id' => 'clients', 'savings_product_id' => 'savings_products', 'created_by' => 'users']],
            'savings_products' => ['label' => 'Savings products', 'category' => 'accounts'],
            'share_transactions' => ['label' => 'Share transactions', 'category' => 'accounts', 'user_columns' => ['created_by'], 'refs' => ['share_account_id' => 'share_accounts', 'created_by' => 'users']],
            'share_accounts' => ['label' => 'Share accounts', 'category' => 'accounts', 'refs' => ['client_id' => 'clients', 'share_product_id' => 'share_products']],
            'share_products' => ['label' => 'Share products', 'category' => 'accounts'],
            'accounts' => ['label' => 'Accounts', 'category' => 'accounts', 'refs' => ['client_id' => 'clients', 'account_type_id' => 'account_types']],
            'account_types' => ['label' => 'Account types', 'category' => 'accounts'],

            'journal_lines' => ['label' => 'Journal lines', 'category' => 'accounting', 'refs' => ['journal_entry_id' => 'journal_entries', 'chart_account_id' => 'chart_accounts']],
            'journal_entries' => ['label' => 'Journal entries', 'category' => 'accounting', 'user_columns' => ['created_by', 'posted_by'], 'refs' => ['transaction_id' => 'transactions', 'created_by' => 'users', 'posted_by' => 'users', 'chart_account_id' => 'chart_accounts', 'reversed_entry_id' => 'journal_entries']],
            'daily_accounting_summaries' => ['label' => 'Daily accounting summaries', 'category' => 'accounting', 'refs' => ['chart_account_id' => 'chart_accounts']],
            'branch_daily_summaries' => ['label' => 'Branch daily summaries', 'category' => 'accounting'],
            'branch_cash_reconciliations' => ['label' => 'Branch cash reconciliations', 'category' => 'accounting', 'user_columns' => ['prepared_by', 'reviewed_by'], 'refs' => ['prepared_by' => 'users', 'reviewed_by' => 'users']],
            'financial_days' => ['label' => 'Financial days', 'category' => 'accounting', 'user_columns' => ['opened_by', 'closed_by'], 'refs' => ['opened_by' => 'users', 'closed_by' => 'users']],
            'transactions' => ['label' => 'Transactions', 'category' => 'accounting', 'user_columns' => ['user_id'], 'refs' => ['account_id' => 'accounts', 'drawer_session_id' => 'drawer_sessions', 'user_id' => 'users', 'reversed_by' => 'transactions', 'reversal_of_id' => 'transactions']],
            'chart_accounts' => ['label' => 'Chart accounts', 'category' => 'accounting', 'refs' => ['parent_id' => 'chart_accounts']],

            'cash_denominations' => ['label' => 'Cash denominations', 'category' => 'teller', 'refs' => ['drawer_session_id' => 'drawer_sessions']],
            'drawer_movements' => ['label' => 'Drawer movements', 'category' => 'teller', 'user_columns' => ['user_id'], 'refs' => ['drawer_session_id' => 'drawer_sessions', 'user_id' => 'users']],
            'drawer_sessions' => ['label' => 'Drawer sessions', 'category' => 'teller', 'user_columns' => ['user_id'], 'refs' => ['teller_drawer_id' => 'teller_drawers', 'user_id' => 'users']],
            'teller_drawers' => ['label' => 'Teller drawers', 'category' => 'teller', 'user_columns' => ['assigned_user_id'], 'refs' => ['assigned_user_id' => 'users']],

            'import_rows' => ['label' => 'Import rows', 'category' => 'imports_sync', 'refs' => ['import_batch_id' => 'import_batches']],
            'import_batches' => ['label' => 'Import batches', 'category' => 'imports_sync', 'user_columns' => ['created_by', 'committed_by', 'rolled_back_by'], 'refs' => ['created_by' => 'users', 'committed_by' => 'users', 'rolled_back_by' => 'users']],
            'offline_sync_records' => ['label' => 'Offline sync records', 'category' => 'imports_sync', 'user_columns' => ['user_id'], 'refs' => ['offline_sync_batch_id' => 'offline_sync_batches', 'user_id' => 'users']],
            'offline_sync_batches' => ['label' => 'Offline sync batches', 'category' => 'imports_sync', 'user_columns' => ['user_id'], 'refs' => ['user_id' => 'users']],
            'idempotency_keys' => ['label' => 'Idempotency keys', 'category' => 'imports_sync', 'user_columns' => ['user_id'], 'refs' => ['user_id' => 'users']],

            'client_relationships' => ['label' => 'Client relationships', 'category' => 'clients', 'refs' => ['client_id' => 'clients', 'related_client_id' => 'clients']],
            'client_group_members' => ['label' => 'Client group members', 'category' => 'clients', 'refs' => ['client_group_id' => 'client_groups', 'client_id' => 'clients']],
            'client_notes' => ['label' => 'Client notes', 'category' => 'clients', 'user_columns' => ['user_id'], 'refs' => ['client_id' => 'clients', 'user_id' => 'users']],
            'client_documents' => ['label' => 'Client documents', 'category' => 'clients', 'user_columns' => ['uploaded_by', 'verified_by'], 'refs' => ['client_id' => 'clients', 'uploaded_by' => 'users', 'verified_by' => 'users']],
            'client_groups' => ['label' => 'Client groups', 'category' => 'clients', 'refs' => ['chairperson_client_id' => 'clients', 'secretary_client_id' => 'clients', 'treasurer_client_id' => 'clients']],
            'clients' => ['label' => 'Clients', 'category' => 'clients', 'user_columns' => ['officer_id', 'member_user_id'], 'refs' => ['officer_id' => 'users', 'member_user_id' => 'users']],

            'personal_access_tokens' => ['label' => 'Personal access tokens', 'category' => 'access_setup'],
            'device_tokens' => ['label' => 'Device tokens', 'category' => 'access_setup', 'user_columns' => ['user_id'], 'refs' => ['user_id' => 'users']],
            'sessions' => ['label' => 'Sessions', 'category' => 'access_setup', 'user_columns' => ['user_id'], 'refs' => ['user_id' => 'users']],
            'permission_role' => ['label' => 'Role permissions', 'category' => 'access_setup', 'refs' => ['permission_id' => 'permissions', 'role_id' => 'roles']],
            'role_financial_limits' => ['label' => 'Role financial limits', 'category' => 'access_setup', 'refs' => ['role_id' => 'roles']],
            'users' => ['label' => 'Staff users', 'category' => 'access_setup', 'refs' => ['branch_id' => 'branches', 'role_id' => 'roles']],
            'permissions' => ['label' => 'Permissions', 'category' => 'access_setup'],
            'roles' => ['label' => 'Roles', 'category' => 'access_setup'],
            'system_settings' => ['label' => 'System settings', 'category' => 'access_setup', 'user_columns' => ['updated_by'], 'refs' => ['updated_by' => 'users']],
            'organization_modules' => ['label' => 'Organization modules', 'category' => 'access_setup'],
            'organization_domains' => ['label' => 'Organization domains', 'category' => 'access_setup'],
            'payment_providers' => ['label' => 'Payment providers', 'category' => 'access_setup'],
            'sms_providers' => ['label' => 'SMS providers', 'category' => 'access_setup'],
            'webhook_providers' => ['label' => 'Webhook providers', 'category' => 'access_setup'],
            'branches' => ['label' => 'Branches', 'category' => 'access_setup', 'user_columns' => ['created_by', 'updated_by', 'manager_user_id'], 'refs' => ['created_by' => 'users', 'updated_by' => 'users', 'manager_user_id' => 'users']],
        ];
    }

    private function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = "{$table}.{$column}";

        return $cache[$key] ??= Schema::hasColumn($table, $column);
    }
}
