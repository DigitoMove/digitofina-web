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

        if (! DB::table('permissions')->where('slug', 'view-arrears')->exists()) {
            $permission = [
                'name' => 'View Arrears',
                'slug' => 'view-arrears',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('permissions', 'organization_id')) {
                $permission['organization_id'] = null;
            }

            DB::table('permissions')->insert($permission);
        }

        if (! DB::table('roles')->where('slug', 'loan-recovery-officer')->exists()) {
            $role = [
                'name' => 'Loan Recovery Officer',
                'slug' => 'loan-recovery-officer',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('roles', 'organization_id')) {
                $role['organization_id'] = null;
            }

            DB::table('roles')->insert($role);
        }

        $this->syncRolePermissions('loan-officer', ['manage-clients', 'verify-kyc', 'manage-loans']);
        $this->syncRolePermissions('teller-cashier', ['post-transactions', 'post-repayments', 'request-reversals']);
        $this->syncRolePermissions('loan-recovery-officer', ['manage-clients', 'view-arrears']);
    }

    public function down(): void {}

    /**
     * @param  array<int, string>  $permissionSlugs
     */
    private function syncRolePermissions(string $roleSlug, array $permissionSlugs): void
    {
        $roleIds = DB::table('roles')->where('slug', $roleSlug)->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('slug', $permissionSlugs)->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->where('role_id', $roleId)->delete();

            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }
};
