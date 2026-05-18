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
        Schema::create('loan_repayment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_repayment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_schedule_id')->constrained()->cascadeOnDelete();
            $table->decimal('penalty_paid', 18, 2)->default(0);
            $table->decimal('fees_paid', 18, 2)->default(0);
            $table->decimal('interest_paid', 18, 2)->default(0);
            $table->decimal('principal_paid', 18, 2)->default(0);
            $table->decimal('total_paid', 18, 2)->default(0);
            $table->timestamps();

            $table->index(['loan_repayment_id', 'loan_schedule_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_repayment_allocations');
    }
};
