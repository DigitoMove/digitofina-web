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
        Schema::create('organization_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('title');
            $table->string('status', 30)->default('draft')->index();
            $table->string('public_token', 64)->unique();
            $table->unsignedInteger('term_months')->default(24);
            $table->decimal('total_fee', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2)->default(0);
            $table->decimal('annual_hosting_fee', 18, 2)->default(200000);
            $table->unsignedInteger('included_branches')->nullable();
            $table->unsignedInteger('included_users')->nullable();
            $table->unsignedInteger('included_clients')->nullable();
            $table->text('included_modules')->nullable();
            $table->date('review_date')->nullable();
            $table->text('special_terms')->nullable();
            $table->text('provider_responsibilities')->nullable();
            $table->text('customer_responsibilities')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_contracts');
    }
};
