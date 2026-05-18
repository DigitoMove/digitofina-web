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
        Schema::table('chart_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('chart_accounts', 'drawer_direction')) {
                $table->string('drawer_direction', 20)->nullable()->after('normal_balance')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chart_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('chart_accounts', 'drawer_direction')) {
                $table->dropIndex(['drawer_direction']);
                $table->dropColumn('drawer_direction');
            }
        });
    }
};
