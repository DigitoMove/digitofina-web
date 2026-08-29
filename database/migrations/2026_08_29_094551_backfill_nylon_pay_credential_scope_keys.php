<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('nylon_pay_credentials')
            ->whereNull('organization_id')
            ->where('profile', 'default')
            ->whereNull('scope_key')
            ->update(['scope_key' => 'platform:default']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('nylon_pay_credentials')
            ->where('scope_key', 'platform:default')
            ->update(['scope_key' => null]);
    }
};
