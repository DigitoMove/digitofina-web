<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssignOrganization extends Command
{
    protected $signature = 'gateoffina:assign-organization
        {--organization= : Organization id or slug}
        {--all : Assign every unassigned tenant-owned record}
        {--branch= : Assign unassigned records for a branch id}
        {--created-by= : Assign unassigned records created by a user id}
        {--dry-run : Show counts without updating records}';

    protected $description = 'Safely assign existing live records to an organization without deleting or resetting data';

    /**
     * @var array<int, array{table: string, branch?: string, user?: array<int, string>, parent?: array{table: string, local: string, parent: string}, source?: array{table: string, local: string, parent: string}}>
     */
    private array $plan = [
        ['table' => 'users', 'branch' => 'branch_id', 'user' => ['id']],
        ['table' => 'branches', 'branch' => 'id'],
        ['table' => 'clients', 'branch' => 'branch_id', 'user' => ['created_by', 'officer_id']],
        ['table' => 'client_documents', 'user' => ['uploaded_by'], 'parent' => ['table' => 'clients', 'local' => 'client_id', 'parent' => 'id']],
        ['table' => 'loan_products', 'user' => ['created_by']],
        ['table' => 'loans', 'branch' => 'branch_id', 'user' => ['created_by', 'officer_id']],
        ['table' => 'loan_applications', 'branch' => 'branch_id', 'user' => ['created_by', 'officer_id']],
        ['table' => 'loan_schedules', 'parent' => ['table' => 'loans', 'local' => 'loan_id', 'parent' => 'id']],
        ['table' => 'loan_repayments', 'branch' => 'branch_id', 'user' => ['received_by'], 'parent' => ['table' => 'loans', 'local' => 'loan_id', 'parent' => 'id']],
        ['table' => 'guarantors', 'parent' => ['table' => 'loans', 'local' => 'loan_id', 'parent' => 'id']],
        ['table' => 'collaterals', 'parent' => ['table' => 'loans', 'local' => 'loan_id', 'parent' => 'id']],
        ['table' => 'savings_products', 'user' => ['created_by']],
        ['table' => 'savings_accounts', 'branch' => 'branch_id', 'parent' => ['table' => 'clients', 'local' => 'client_id', 'parent' => 'id']],
        ['table' => 'savings_transactions', 'branch' => 'branch_id', 'user' => ['created_by', 'approved_by'], 'parent' => ['table' => 'savings_accounts', 'local' => 'savings_account_id', 'parent' => 'id']],
        ['table' => 'investment_plans', 'branch' => 'branch_id', 'user' => ['created_by']],
        ['table' => 'investor_accounts', 'branch' => 'branch_id', 'parent' => ['table' => 'clients', 'local' => 'client_id', 'parent' => 'id']],
        ['table' => 'investment_accounts', 'branch' => 'branch_id', 'parent' => ['table' => 'investor_accounts', 'local' => 'investor_account_id', 'parent' => 'id']],
        ['table' => 'investment_transactions', 'branch' => 'branch_id', 'user' => ['created_by', 'approved_by'], 'parent' => ['table' => 'investment_accounts', 'local' => 'investment_account_id', 'parent' => 'id']],
        ['table' => 'journal_entries', 'branch' => 'branch_id', 'user' => ['created_by', 'posted_by']],
        ['table' => 'journal_lines', 'parent' => ['table' => 'journal_entries', 'local' => 'journal_entry_id', 'parent' => 'id']],
        ['table' => 'sms_logs', 'user' => ['user_id']],
        ['table' => 'audit_logs', 'branch' => 'branch_id', 'user' => ['user_id']],
        ['table' => 'system_settings', 'user' => ['updated_by']],
        ['table' => 'document_records', 'branch' => 'branch_id', 'user' => ['uploaded_by'], 'source' => ['table' => 'clients', 'local' => 'documentable_id', 'parent' => 'id']],
        ['table' => 'report_exports', 'branch' => 'branch_id', 'user' => ['requested_by']],
    ];

    public function handle(): int
    {
        $organization = $this->organization();

        if (! $organization) {
            return self::FAILURE;
        }

        $modes = collect(['all', 'branch', 'created-by'])
            ->filter(fn (string $option): bool => filled($this->option($option)));

        if ($modes->count() !== 1) {
            $this->error('Choose exactly one assignment mode: --all, --branch, or --created-by.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $total = 0;

        DB::transaction(function () use ($organization, $dryRun, &$rows, &$total): void {
            foreach ($this->plan as $step) {
                if (! $this->assignable($step['table'])) {
                    continue;
                }

                $query = DB::table($step['table'])->whereNull('organization_id');
                $this->applyMode($query, $step);
                $count = (clone $query)->count();

                if ($count > 0 && ! $dryRun) {
                    $query->update(['organization_id' => $organization->id]);
                }

                $rows[] = [$step['table'], $count, $dryRun ? 'dry-run' : 'updated'];
                $total += $count;
            }
        });

        $this->table(['Table', 'Records', 'Action'], $rows);
        $this->info(($dryRun ? 'Would assign ' : 'Assigned ').$total." record(s) to {$organization->name}.");

        return self::SUCCESS;
    }

    private function organization(): ?Organization
    {
        $key = $this->option('organization');

        if (! filled($key)) {
            $this->error('The --organization option is required.');

            return null;
        }

        $organization = Organization::query()
            ->where(function ($query) use ($key): void {
                $query->where('slug', $key);

                if (is_numeric($key)) {
                    $query->orWhere('id', (int) $key);
                }
            })
            ->first();

        if (! $organization) {
            $this->error("Organization [{$key}] was not found.");
        }

        return $organization;
    }

    /**
     * @param  array{table: string, branch?: string, user?: array<int, string>, parent?: array{table: string, local: string, parent: string}, source?: array{table: string, local: string, parent: string}}  $step
     */
    private function applyMode(Builder $query, array $step): void
    {
        if ($this->option('all')) {
            return;
        }

        if (filled($this->option('branch'))) {
            $this->applyBranchMode($query, $step, (int) $this->option('branch'));

            return;
        }

        $this->applyUserMode($query, $step, (int) $this->option('created-by'));
    }

    /**
     * @param  array{table: string, branch?: string, parent?: array{table: string, local: string, parent: string}, source?: array{table: string, local: string, parent: string}}  $step
     */
    private function applyBranchMode(Builder $query, array $step, int $branchId): void
    {
        if (isset($step['branch']) && Schema::hasColumn($step['table'], $step['branch'])) {
            $query->where($step['branch'], $branchId);

            return;
        }

        $relation = $step['parent'] ?? $step['source'] ?? null;

        if ($relation && $this->assignable($relation['table']) && Schema::hasColumn($relation['table'], 'branch_id') && Schema::hasColumn($step['table'], $relation['local'])) {
            $query->whereIn($relation['local'], DB::table($relation['table'])->where('branch_id', $branchId)->pluck($relation['parent']));

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * @param  array{table: string, user?: array<int, string>}  $step
     */
    private function applyUserMode(Builder $query, array $step, int $userId): void
    {
        $columns = collect($step['user'] ?? [])
            ->filter(fn (string $column): bool => Schema::hasColumn($step['table'], $column))
            ->values();

        if ($columns->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $query) use ($columns, $userId): void {
            foreach ($columns as $index => $column) {
                $index === 0
                    ? $query->where($column, $userId)
                    : $query->orWhere($column, $userId);
            }
        });
    }

    private function assignable(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'organization_id');
    }
}
