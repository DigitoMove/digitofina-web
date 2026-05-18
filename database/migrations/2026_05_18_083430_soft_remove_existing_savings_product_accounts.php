<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('savings_accounts')) {
            return;
        }

        DB::table('savings_accounts')
            ->whereNull('deleted_at')
            ->update([
                'status' => 'removed',
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('savings_accounts')) {
            return;
        }

        DB::table('savings_accounts')
            ->whereNotNull('deleted_at')
            ->where('status', 'removed')
            ->update([
                'status' => 'active',
                'deleted_at' => null,
                'updated_at' => now(),
            ]);
    }
};
