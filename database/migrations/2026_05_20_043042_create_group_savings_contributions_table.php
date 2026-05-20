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
        if (Schema::hasTable('group_savings_contributions')) {
            Schema::table('group_savings_contributions', function (Blueprint $table) {
                $table->index(['group_savings_account_id', 'client_id'], 'gsc_account_client_idx');
            });

            return;
        }

        Schema::create('group_savings_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('group_savings_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 80)->unique();
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_before', 18, 2)->default(0);
            $table->decimal('balance_after', 18, 2)->default(0);
            $table->date('contribution_date')->index();
            $table->string('payment_method', 40)->default('cash');
            $table->text('narration')->nullable();
            $table->string('status', 30)->default('successful')->index();
            $table->timestamps();

            $table->index(['group_savings_account_id', 'client_id'], 'gsc_account_client_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_savings_contributions');
    }
};
