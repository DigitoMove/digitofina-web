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
        Schema::create('daily_accounting_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_day_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chart_account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('business_date')->index();
            $table->string('account_code', 40)->nullable();
            $table->string('account_name');
            $table->decimal('debit_total', 18, 2)->default(0);
            $table->decimal('credit_total', 18, 2)->default(0);
            $table->decimal('net_movement', 18, 2)->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'chart_account_id'], 'daily_accounting_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_accounting_summaries');
    }
};
