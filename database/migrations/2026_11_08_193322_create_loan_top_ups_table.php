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
        Schema::create('loan_top_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('loan_disbursement_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('submission_token')->unique();
            $table->decimal('principal_added', 18, 2);
            $table->decimal('interest_added', 18, 2);
            $table->unsignedInteger('extension_days');
            $table->date('previous_maturity_date');
            $table->date('new_maturity_date');
            $table->string('previous_loan_status', 30);
            $table->unsignedBigInteger('latest_repayment_id')->default(0);
            $table->json('schedule_before');
            $table->json('schedule_after');
            $table->string('reason', 1000);
            $table->string('status', 30)->default('posted');
            $table->index(['loan_id', 'status']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_top_ups');
    }
};
