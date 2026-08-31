<?php

use App\Services\LedgerReversalRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->syncOrganizationFromParent('loan_disbursements', 'loans', 'loan_id');
        $this->syncOrganizationFromParent('loan_disbursements', 'branches', 'branch_id');
        $this->syncOrganizationFromParent('transactions', 'branches', 'branch_id');
        $this->syncOrganizationFromParent('journal_entries', 'transactions', 'transaction_id');
        $this->syncOrganizationFromParent('journal_entries', 'branches', 'branch_id');
        $this->syncOrganizationFromParent('journal_lines', 'journal_entries', 'journal_entry_id');

        $this->assignMissingApplicationFeeAccounts();

        app(LedgerReversalRepairService::class)->repairMissingLines(dryRun: false);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Historical accounting repairs are intentionally not reversed.
    }

    private function syncOrganizationFromParent(string $table, string $parentTable, string $foreignKey): void
    {
        if (
            ! Schema::hasTable($table)
            || ! Schema::hasTable($parentTable)
            || ! Schema::hasColumn($table, 'organization_id')
            || ! Schema::hasColumn($parentTable, 'organization_id')
            || ! Schema::hasColumn($table, $foreignKey)
        ) {
            return;
        }

        DB::table($parentTable)
            ->whereNotNull('organization_id')
            ->select(['id', 'organization_id'])
            ->chunkById(500, function (Collection $parents) use ($table, $foreignKey): void {
                $parents->groupBy('organization_id')
                    ->each(function (Collection $organizationParents, int|string $organizationId) use ($table, $foreignKey): void {
                        DB::table($table)
                            ->whereIn($foreignKey, $organizationParents->pluck('id'))
                            ->where(function (Builder $query) use ($organizationId): void {
                                $query->whereNull('organization_id')
                                    ->orWhere('organization_id', '!=', (int) $organizationId);
                            })
                            ->update(['organization_id' => (int) $organizationId]);
                    });
            });
    }

    private function assignMissingApplicationFeeAccounts(): void
    {
        if (
            ! Schema::hasTable('journal_entries')
            || ! Schema::hasTable('chart_accounts')
            || ! Schema::hasTable('branches')
            || ! Schema::hasColumn('journal_entries', 'chart_account_id')
            || ! Schema::hasColumn('journal_entries', 'branch_id')
        ) {
            return;
        }

        $branchIds = $this->applicationFeeJournalQuery()
            ->whereNull('chart_account_id')
            ->whereNotNull('branch_id')
            ->pluck('branch_id')
            ->unique();

        DB::table('branches')
            ->whereIn('id', $branchIds)
            ->get(['id', 'organization_id', 'code'])
            ->each(function (object $branch): void {
                $accountId = DB::table('chart_accounts')
                    ->where('organization_id', $branch->organization_id)
                    ->where('code', $branch->code.'-4330')
                    ->value('id');

                if (! $accountId) {
                    $accountId = DB::table('chart_accounts')->insertGetId([
                        'organization_id' => $branch->organization_id,
                        'branch_id' => $branch->id,
                        'code' => $branch->code.'-4330',
                        'name' => 'Application Fees Income',
                        'type' => 'income',
                        'normal_balance' => 'credit',
                        'is_cash_account' => false,
                        'is_system' => false,
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]);
                }

                $journalIds = $this->applicationFeeJournalQuery()
                    ->where('branch_id', $branch->id)
                    ->whereNull('chart_account_id')
                    ->pluck('id');

                DB::table('journal_entries')
                    ->whereIn('id', $journalIds)
                    ->update([
                        'chart_account_id' => $accountId,
                        'account_name' => 'Application Fees Income',
                        'updated_at' => now(),
                    ]);

                if (Schema::hasColumn('journal_entries', 'reversed_entry_id')) {
                    DB::table('journal_entries')
                        ->whereIn('reversed_entry_id', $journalIds)
                        ->update([
                            'chart_account_id' => $accountId,
                            'account_name' => 'Application Fees Income',
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    private function applicationFeeJournalQuery(): Builder
    {
        return DB::table('journal_entries')
            ->where('entry_type', 'credit')
            ->where(function (Builder $query): void {
                $query->where('memo', 'like', '%application fee%')
                    ->orWhere('description', 'like', '%application fee%');
            });
    }
};
