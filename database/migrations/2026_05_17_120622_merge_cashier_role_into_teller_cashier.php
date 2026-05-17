<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('users')) {
            return;
        }

        $tellerRole = DB::table('roles')->where('slug', 'teller-cashier')->first();
        $cashierRole = DB::table('roles')->where('slug', 'cashier')->first();

        if (! $tellerRole || ! $cashierRole) {
            return;
        }

        DB::table('users')
            ->where('role_id', $cashierRole->id)
            ->update(['role_id' => $tellerRole->id, 'updated_at' => now()]);

        if (Schema::hasColumn('roles', 'deleted_at')) {
            DB::table('roles')
                ->where('id', $cashierRole->id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'deleted_at')) {
            DB::table('roles')
                ->where('slug', 'cashier')
                ->update(['deleted_at' => null, 'updated_at' => now()]);
        }
    }
};
