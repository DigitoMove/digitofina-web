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

        $permissionId = $this->viewReportsPermissionId();

        DB::table('roles')
            ->whereIn('slug', ['teller-cashier', 'cashier', 'teller'])
            ->pluck('id')
            ->each(function (int $roleId) use ($permissionId): void {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            });
    }

    public function down(): void
    {
        //
    }

    private function viewReportsPermissionId(): int
    {
        $permissionId = DB::table('permissions')->where('slug', 'view-reports')->value('id');
        $permission = [
            'name' => 'View Reports',
            'slug' => 'view-reports',
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('permissions', 'organization_id')) {
            $permission['organization_id'] = null;
        }

        if ($permissionId) {
            DB::table('permissions')->where('id', $permissionId)->update($permission);

            return (int) $permissionId;
        }

        return (int) DB::table('permissions')->insertGetId($permission + ['created_at' => now()]);
    }
};
