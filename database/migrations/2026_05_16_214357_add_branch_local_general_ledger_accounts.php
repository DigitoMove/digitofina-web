<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chart_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('chart_accounts', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
                $table->index(['branch_id', 'type', 'is_active']);
            }
        });

        $branches = DB::table('branches')->get(['id', 'organization_id', 'code']);
        $accounts = [
            ['suffix' => '5200', 'name' => 'Welfare Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['suffix' => '5210', 'name' => 'Transport Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['suffix' => '5220', 'name' => 'Travel Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['suffix' => '5230', 'name' => 'Office Supplies Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['suffix' => '5290', 'name' => 'Other Expenses', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['suffix' => '4300', 'name' => 'Other Income', 'type' => 'income', 'normal_balance' => 'credit'],
            ['suffix' => '4310', 'name' => 'Welfare Income', 'type' => 'income', 'normal_balance' => 'credit'],
            ['suffix' => '4320', 'name' => 'Other Business Income', 'type' => 'income', 'normal_balance' => 'credit'],
        ];

        foreach ($branches as $branch) {
            foreach ($accounts as $account) {
                DB::table('chart_accounts')->updateOrInsert(
                    ['code' => $branch->code.'-'.$account['suffix']],
                    [
                        'organization_id' => $branch->organization_id,
                        'branch_id' => $branch->id,
                        'name' => $account['name'],
                        'type' => $account['type'],
                        'normal_balance' => $account['normal_balance'],
                        'is_cash_account' => false,
                        'is_system' => false,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('chart_accounts')
            ->whereIn('name', ['Welfare Expense', 'Transport Expense', 'Travel Expense', 'Office Supplies Expense', 'Other Expenses', 'Other Income', 'Welfare Income', 'Other Business Income'])
            ->where('is_system', false)
            ->delete();

        Schema::table('chart_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('chart_accounts', 'branch_id')) {
                $table->dropIndex(['branch_id', 'type', 'is_active']);
                $table->dropConstrainedForeignId('branch_id');
            }
        });
    }
};
