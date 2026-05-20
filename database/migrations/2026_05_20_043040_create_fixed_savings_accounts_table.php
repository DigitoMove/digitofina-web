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
        Schema::create('fixed_savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('savings_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('account_number', 80)->unique();
            $table->decimal('principal_amount', 18, 2);
            $table->date('start_date');
            $table->date('maturity_date');
            $table->decimal('interest_rate', 8, 4)->default(0);
            $table->decimal('expected_interest', 18, 2)->default(0);
            $table->decimal('actual_interest', 18, 2)->default(0);
            $table->boolean('early_withdrawal_allowed')->default(false);
            $table->decimal('early_withdrawal_penalty', 8, 4)->default(0);
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['maturity_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_savings_accounts');
    }
};
