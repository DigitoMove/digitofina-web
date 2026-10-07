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
        Schema::create('loan_savings_deduction_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('frequency', 20);
            $table->timestamp('next_run_at')->index();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_result', 20)->nullable();
            $table->text('last_message')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'branch_id', 'status', 'next_run_at'], 'loan_savings_schedule_due_index');
            $table->index(['loan_id', 'savings_account_id', 'status'], 'loan_savings_schedule_pair_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_savings_deduction_schedules');
    }
};
