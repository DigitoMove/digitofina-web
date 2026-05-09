<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_number', 40)->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 40)->index();
            $table->string('email', 191)->nullable();
            $table->string('national_id', 80)->nullable()->unique();
            $table->string('photo_path')->nullable();
            $table->text('address')->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone', 40)->nullable();
            $table->string('employment_status')->nullable();
            $table->string('business_name')->nullable();
            $table->text('business_details')->nullable();
            $table->json('kyc_documents')->nullable();
            $table->string('kyc_status', 30)->default('pending')->index();
            $table->string('status', 30)->default('active')->index();
            $table->date('registered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('account_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->string('category', 40)->index();
            $table->decimal('minimum_balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_id')->nullable();
            $table->string('account_number', 40)->unique();
            $table->string('account_name');
            $table->string('status', 30)->default('active')->index();
            $table->decimal('balance', 18, 2)->default(0);
            $table->date('opened_at');
            $table->timestamps();
        });

        Schema::create('transaction_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('direction', 20);
            $table->boolean('affects_cash_drawer')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('drawer_session_id')->nullable();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('reference', 50)->unique();
            $table->string('cheque_number')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_after', 18, 2)->nullable();
            $table->string('currency', 10)->default('UGX');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('posted')->index();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('transaction_types');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('account_types');
        Schema::dropIfExists('clients');
    }
};
