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
        Schema::create('financial_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('business_date')->index();
            $table->string('status', 30)->default('open')->index();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date']);
        });

        Schema::create('role_financial_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->decimal('single_transaction_limit', 18, 2)->nullable();
            $table->decimal('daily_transaction_limit', 18, 2)->nullable();
            $table->decimal('maker_checker_threshold', 18, 2)->nullable();
            $table->decimal('loan_approval_limit', 18, 2)->nullable();
            $table->decimal('backdated_transaction_limit', 18, 2)->nullable();
            $table->decimal('suspicious_transaction_threshold', 18, 2)->nullable();
            $table->unsignedInteger('max_backdate_days')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['organization_id', 'role_id']);
        });

        Schema::create('maker_checker_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80)->index();
            $table->string('required_permission', 80)->nullable()->index();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->json('old_values')->nullable();
            $table->json('payload')->nullable();
            $table->text('reason')->nullable();
            $table->text('checker_comments')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('branch_cash_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_day_id')->nullable()->constrained()->nullOnDelete();
            $table->date('business_date')->index();
            $table->decimal('expected_cash', 18, 2)->default(0);
            $table->decimal('counted_cash', 18, 2)->default(0);
            $table->decimal('variance', 18, 2)->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date']);
        });

        Schema::create('suspicious_transaction_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('alertable');
            $table->string('type', 80)->index();
            $table->string('severity', 30)->default('medium')->index();
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('threshold', 18, 2)->nullable();
            $table->string('status', 30)->default('open')->index();
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suspicious_transaction_alerts');
        Schema::dropIfExists('branch_cash_reconciliations');
        Schema::dropIfExists('maker_checker_requests');
        Schema::dropIfExists('role_financial_limits');
        Schema::dropIfExists('financial_days');
    }
};
