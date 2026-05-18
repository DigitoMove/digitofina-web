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
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'reversal_of_id')) {
                $table->foreignId('reversal_of_id')->nullable()->after('reversed_by')->constrained('transactions')->nullOnDelete();
            }

            if (! Schema::hasColumn('transactions', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'reversal_of_id')) {
                $table->dropConstrainedForeignId('reversal_of_id');
            }

            if (Schema::hasColumn('transactions', 'reversed_at')) {
                $table->dropColumn('reversed_at');
            }
        });
    }
};
