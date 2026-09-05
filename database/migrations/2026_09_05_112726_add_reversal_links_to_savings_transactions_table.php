<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('savings_transactions')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('savings_transactions')->whereNotNull('reversal_of_id')->exists()) {
            throw new RuntimeException('Cannot remove reversal links after compensating postings exist. Keep this additive schema when rolling back application code.');
        }

        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn('reversed_at');
        });
    }
};
