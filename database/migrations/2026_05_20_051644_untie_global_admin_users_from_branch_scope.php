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
        DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.slug', 'super-admin')
            ->update([
                'users.organization_id' => null,
                'users.branch_id' => null,
                'users.updated_at' => now(),
            ]);

        DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.slug', 'organization-admin')
            ->update([
                'users.branch_id' => null,
                'users.updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally not restoring branch bindings. They were access constraints, not source data.
    }
};
