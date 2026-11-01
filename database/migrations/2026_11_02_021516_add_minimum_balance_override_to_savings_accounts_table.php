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
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->decimal('minimum_balance_override', 18, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->dropColumn('minimum_balance_override');
        });
    }
};
