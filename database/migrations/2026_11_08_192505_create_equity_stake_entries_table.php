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
        if (! Schema::hasTable('equity_stake_entries')) {
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
            });
        }

        if (! Schema::hasIndex('equity_stake_entries', 'stake_journal_contributor_idx')) {
            Schema::table('equity_stake_entries', function (Blueprint $table) {
                $table->index(['journal_entry_id', 'equity_contributor_id'], 'stake_journal_contributor_idx');
            });
        }

        if (! Schema::hasIndex('equity_stake_entries', 'stake_account_type_idx')) {
            Schema::table('equity_stake_entries', function (Blueprint $table) {
                $table->index(['chart_account_id', 'entry_type'], 'stake_account_type_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equity_stake_entries');
    }
};
