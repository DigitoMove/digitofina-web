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
        Schema::create('loan_product_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->decimal('min_amount', 18, 2)->default(0);
            $table->decimal('max_amount', 18, 2)->default(0);
            $table->unsignedInteger('min_term')->default(1);
            $table->unsignedInteger('max_term')->default(60);
            $table->string('term_unit', 20)->default('months');
            $table->decimal('annual_interest_rate', 8, 4);
            $table->boolean('is_interest_free')->default(false);
            $table->string('interest_method', 30)->default('reducing_balance');
            $table->string('interest_frequency', 20)->default('monthly');
            $table->string('repayment_frequency', 30)->default('monthly');
            $table->integer('default_term_months')->default(12);
            $table->integer('grace_period_days')->default(0);
            $table->string('penalty_type', 20)->default('percentage');
            $table->decimal('penalty_rate', 8, 4)->default(0);
            $table->decimal('processing_fee', 18, 2)->default(0);
            $table->decimal('insurance_fee', 18, 2)->default(0);
            $table->decimal('application_fee', 18, 2)->default(0);
            $table->decimal('required_savings_percentage', 8, 4)->default(0);
            $table->boolean('require_guarantors')->default(false);
            $table->unsignedInteger('minimum_guarantors')->default(0);
            $table->boolean('require_collateral')->default(false);
            $table->boolean('auto_deduct_repayment_from_savings')->default(false);
            $table->string('loan_account_number_prefix', 20)->default('LN');
            $table->boolean('is_active')->default(true)->index();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_product_templates');
    }
};
