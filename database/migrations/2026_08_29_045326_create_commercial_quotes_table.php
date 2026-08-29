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
        Schema::create('commercial_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('quote_number', 40)->unique();
            $table->string('billing_type', 30)->default('subscription')->index();
            $table->unsignedSmallInteger('subscription_months')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('currency', 10)->default('UGX');
            $table->text('requested_scope');
            $table->text('terms')->nullable();
            $table->string('status', 30)->default('requested')->index();
            $table->date('valid_until')->nullable()->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('payment_initiated_at')->nullable();
            $table->timestamp('paid_at')->nullable()->index();
            $table->string('nylon_reference', 15)->nullable()->unique();
            $table->string('nylon_invoice_id', 191)->nullable()->index();
            $table->text('nylon_checkout_url')->nullable();
            $table->string('nylon_payment_status', 40)->nullable()->index();
            $table->text('payment_metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_quotes');
    }
};
