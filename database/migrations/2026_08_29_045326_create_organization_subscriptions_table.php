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
        Schema::create('organization_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commercial_quote_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('billing_type', 30)->index();
            $table->string('status', 30)->default('active')->index();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 10)->default('UGX');
            $table->string('provider', 50)->default('nylon-pay');
            $table->string('provider_reference', 191)->unique();
            $table->timestamp('starts_at');
            $table->timestamp('current_period_ends_at')->nullable()->index();
            $table->timestamp('activated_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'current_period_ends_at'], 'org_subscription_status_period_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_subscriptions');
    }
};
