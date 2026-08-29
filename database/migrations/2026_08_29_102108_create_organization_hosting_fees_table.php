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
        Schema::create('organization_hosting_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commercial_quote_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->date('due_on')->index();
            $table->decimal('amount', 18, 2)->default(200000);
            $table->string('currency', 10)->default('UGX');
            $table->string('status', 30)->default('due')->index();
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference', 191)->nullable()->unique();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamp('waived_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'period_starts_on'], 'organization_hosting_period_unique');
            $table->index(['organization_id', 'status', 'due_on'], 'organization_hosting_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_hosting_fees');
    }
};
