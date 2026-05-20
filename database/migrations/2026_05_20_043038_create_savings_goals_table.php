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
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('savings_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->decimal('target_amount', 18, 2);
            $table->decimal('current_saved_amount', 18, 2)->default(0);
            $table->date('start_date');
            $table->date('target_date');
            $table->string('contribution_frequency', 30)->default('monthly');
            $table->decimal('expected_contribution_amount', 18, 2)->default(0);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['target_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('savings_goals');
    }
};
