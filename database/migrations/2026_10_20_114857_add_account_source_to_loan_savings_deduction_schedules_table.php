<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loan_savings_deduction_schedules', function (Blueprint $table) {
            $table->foreignId('savings_account_id')->nullable()->change();
            $table->foreignId('account_id')->nullable()->after('savings_account_id')->constrained()->cascadeOnDelete();
            $table->index(['loan_id', 'account_id', 'status'], 'loan_account_schedule_pair_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_savings_deduction_schedules', function (Blueprint $table) {
            $table->dropIndex('loan_account_schedule_pair_index');
            $table->dropConstrainedForeignId('account_id');
            $table->foreignId('savings_account_id')->nullable(false)->change();
        });
    }
};
