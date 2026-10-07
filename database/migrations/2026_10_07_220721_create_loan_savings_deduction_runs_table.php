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
        Schema::create('loan_savings_deduction_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('loan_savings_deduction_schedules')->cascadeOnDelete();
            $table->foreignId('savings_transaction_id')->nullable()->constrained('savings_transactions')->nullOnDelete();
            $table->foreignId('loan_repayment_id')->nullable()->constrained('loan_repayments')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamp('due_at');
            $table->string('idempotency_key', 180)->unique();
            $table->string('status', 20)->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'due_at']);
            $table->index(['organization_id', 'branch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_savings_deduction_runs');
    }
};
