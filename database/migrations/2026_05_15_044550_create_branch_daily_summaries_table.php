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
        Schema::create('branch_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_day_id')->nullable()->constrained()->nullOnDelete();
            $table->date('business_date')->index();
            $table->unsignedInteger('teller_sessions_opened')->default(0);
            $table->unsignedInteger('teller_sessions_closed')->default(0);
            $table->unsignedInteger('open_teller_sessions')->default(0);
            $table->decimal('opening_cash', 18, 2)->default(0);
            $table->decimal('expected_cash', 18, 2)->default(0);
            $table->decimal('counted_cash', 18, 2)->default(0);
            $table->decimal('cash_variance', 18, 2)->default(0);
            $table->unsignedInteger('cash_transaction_count')->default(0);
            $table->decimal('cash_in', 18, 2)->default(0);
            $table->decimal('cash_out', 18, 2)->default(0);
            $table->unsignedInteger('savings_transaction_count')->default(0);
            $table->decimal('savings_deposits', 18, 2)->default(0);
            $table->decimal('savings_withdrawals', 18, 2)->default(0);
            $table->unsignedInteger('loan_repayment_count')->default(0);
            $table->decimal('loan_repayments', 18, 2)->default(0);
            $table->unsignedInteger('loan_disbursement_count')->default(0);
            $table->decimal('loan_disbursements', 18, 2)->default(0);
            $table->unsignedInteger('overdue_installments')->default(0);
            $table->decimal('penalties_applied', 18, 2)->default(0);
            $table->unsignedInteger('reminders_queued')->default(0);
            $table->decimal('journal_debits', 18, 2)->default(0);
            $table->decimal('journal_credits', 18, 2)->default(0);
            $table->string('status', 30)->default('generated')->index();
            $table->json('metadata')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_daily_summaries');
    }
};
