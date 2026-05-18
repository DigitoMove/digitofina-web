<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $approveLoanPermissions = DB::table('permissions')->where('slug', 'approve-loans')->get(['id', 'organization_id']);

        DB::table('roles')
            ->where('slug', 'loan-officer')
            ->orderBy('id')
            ->each(function (object $role) use ($approveLoanPermissions): void {
                $permission = $approveLoanPermissions->firstWhere('organization_id', $role->organization_id)
                    ?? $approveLoanPermissions->firstWhere('organization_id', null)
                    ?? $approveLoanPermissions->first();

                if (! $permission) {
                    return;
                }

                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permission->id,
                    'role_id' => $role->id,
                ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionIds = DB::table('permissions')->where('slug', 'approve-loans')->pluck('id');
        $roleIds = DB::table('roles')->where('slug', 'loan-officer')->pluck('id');

        if ($permissionIds->isEmpty() || $roleIds->isEmpty()) {
            return;
        }

        DB::table('permission_role')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('role_id', $roleIds)
            ->delete();
    }
};
