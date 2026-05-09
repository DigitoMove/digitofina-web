<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->decimal('min_amount', 18, 2)->default(0);
            $table->decimal('max_amount', 18, 2)->default(0);
            $table->decimal('annual_interest_rate', 8, 4);
            $table->string('interest_method', 30)->default('reducing_balance');
            $table->string('repayment_frequency', 30)->default('monthly');
            $table->integer('default_term_months')->default(12);
            $table->integer('grace_period_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_product_id')->constrained()->restrictOnDelete();
            $table->foreignId('savings_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('loan_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('loan_number', 50)->unique();
            $table->decimal('requested_amount', 18, 2);
            $table->decimal('approved_amount', 18, 2)->nullable();
            $table->decimal('principal_balance', 18, 2)->default(0);
            $table->decimal('interest_balance', 18, 2)->default(0);
            $table->decimal('fees_balance', 18, 2)->default(0);
            $table->decimal('penalty_balance', 18, 2)->default(0);
            $table->decimal('interest_rate', 8, 4);
            $table->integer('term_periods');
            $table->string('repayment_frequency', 30);
            $table->string('purpose')->nullable();
            $table->text('collateral_details')->nullable();
            $table->json('guarantors')->nullable();
            $table->json('documents')->nullable();
            $table->text('credit_officer_comments')->nullable();
            $table->json('appraisal')->nullable();
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->decimal('recommended_amount', 18, 2)->nullable();
            $table->text('officer_recommendation')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->date('submitted_at')->nullable();
            $table->date('approved_at')->nullable();
            $table->date('disbursed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->integer('installment_number');
            $table->date('due_date');
            $table->decimal('principal_due', 18, 2)->default(0);
            $table->decimal('interest_due', 18, 2)->default(0);
            $table->decimal('fees_due', 18, 2)->default(0);
            $table->decimal('penalty_due', 18, 2)->default(0);
            $table->decimal('total_due', 18, 2)->default(0);
            $table->decimal('amount_paid', 18, 2)->default(0);
            $table->decimal('outstanding_amount', 18, 2)->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->timestamps();
        });

        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->decimal('penalty_paid', 18, 2)->default(0);
            $table->decimal('fees_paid', 18, 2)->default(0);
            $table->decimal('interest_paid', 18, 2)->default(0);
            $table->decimal('principal_paid', 18, 2)->default(0);
            $table->date('paid_at');
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('approvable');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('level', 60);
            $table->string('status', 30)->default('pending')->index();
            $table->text('comments')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 60)->index();
            $table->string('account_name');
            $table->string('entry_type', 10);
            $table->decimal('amount', 18, 2);
            $table->text('memo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('loan_schedules');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('loan_products');
    }
};
