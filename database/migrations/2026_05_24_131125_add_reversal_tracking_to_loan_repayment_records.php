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
        Schema::table('loan_repayments', function (Blueprint $table): void {
            if (! Schema::hasColumn('loan_repayments', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('loan_repayments', 'reversed_by')) {
                $table->foreignId('reversed_by')->nullable()->after('reversed_at');
            }

            if (! Schema::hasColumn('loan_repayments', 'reversal_reason')) {
                $table->text('reversal_reason')->nullable()->after('reversed_by');
            }

            if (! Schema::hasColumn('loan_repayments', 'reversal_transaction_id')) {
                $table->foreignId('reversal_transaction_id')->nullable()->after('transaction_id');
            }
        });

        Schema::table('loan_repayment_allocations', function (Blueprint $table): void {
            if (! Schema::hasColumn('loan_repayment_allocations', 'status')) {
                $table->string('status', 40)->default('applied')->after('total_paid')->index();
            }

            if (! Schema::hasColumn('loan_repayment_allocations', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('loan_repayment_allocations', 'reversed_by')) {
                $table->foreignId('reversed_by')->nullable()->after('reversed_at');
            }
        });

        Schema::table('loan_schedules', function (Blueprint $table): void {
            if (! Schema::hasColumn('loan_schedules', 'last_reversal_at')) {
                $table->timestamp('last_reversal_at')->nullable()->after('days_overdue');
            }

            if (! Schema::hasColumn('loan_schedules', 'last_reversal_by')) {
                $table->foreignId('last_reversal_by')->nullable()->after('last_reversal_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_schedules', function (Blueprint $table): void {
            foreach (['last_reversal_by', 'last_reversal_at'] as $column) {
                if (Schema::hasColumn('loan_schedules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('loan_repayment_allocations', function (Blueprint $table): void {
            foreach (['reversed_by', 'reversed_at', 'status'] as $column) {
                if (Schema::hasColumn('loan_repayment_allocations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('loan_repayments', function (Blueprint $table): void {
            foreach (['reversal_transaction_id', 'reversal_reason', 'reversed_by', 'reversed_at'] as $column) {
                if (Schema::hasColumn('loan_repayments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
