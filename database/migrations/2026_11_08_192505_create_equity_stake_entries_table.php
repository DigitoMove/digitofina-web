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
        Schema::create('equity_stake_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chart_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('equity_contributor_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('entry_type', 6);
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index(['journal_entry_id', 'equity_contributor_id']);
            $table->index(['chart_account_id', 'entry_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equity_stake_entries');
    }
};
