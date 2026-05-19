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
        Schema::create('investment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('code', 40);
            $table->text('description')->nullable();
            $table->string('type', 40)->index();
            $table->decimal('minimum_amount', 18, 2)->default(0);
            $table->decimal('maximum_amount', 18, 2)->nullable();
            $table->unsignedInteger('duration');
            $table->string('duration_unit', 20)->default('months');
            $table->decimal('expected_return_rate', 8, 4)->default(0);
            $table->string('return_calculation_method', 40)->default('fixed_percentage');
            $table->string('return_frequency', 30)->default('at_maturity');
            $table->boolean('allow_top_ups')->default(false);
            $table->boolean('allow_early_withdrawal')->default(false);
            $table->decimal('early_withdrawal_penalty', 8, 4)->default(0);
            $table->boolean('auto_reinvest_returns')->default(false);
            $table->string('risk_level', 20)->default('low');
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investment_plans');
    }
};
