<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('chart_accounts') || ! Schema::hasColumn('chart_accounts', 'branch_id')) {
            return;
        }

        $branches = DB::table('branches')->get(['id', 'organization_id', 'code']);

        foreach ($branches as $branch) {
            DB::table('chart_accounts')->updateOrInsert(
                ['code' => $branch->code.'-4320'],
                [
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'name' => 'Other Business Income',
                    'type' => 'income',
                    'normal_balance' => 'credit',
                    'is_cash_account' => false,
                    'is_system' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('chart_accounts')) {
            return;
        }

        DB::table('chart_accounts')
            ->where('name', 'Other Business Income')
            ->where('is_system', false)
            ->delete();
    }
};
