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

        $this->consolidateRole('loan-officer', 'Loan Officer', ['field-officer', 'officer']);
        $this->consolidateRole('supervisor', 'Supervisor', ['admin', 'management']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        if (Schema::hasColumn('roles', 'deleted_at')) {
            DB::table('roles')
                ->whereIn('slug', ['field-officer', 'officer', 'admin', 'management'])
                ->update(['deleted_at' => null, 'updated_at' => now()]);
        }
    }

    /**
     * @param  array<int, string>  $duplicateSlugs
     */
    private function consolidateRole(string $targetSlug, string $targetName, array $duplicateSlugs): void
    {
        $now = now();
        $targetRole = DB::table('roles')->where('slug', $targetSlug)->first();
        $duplicateRoles = DB::table('roles')->whereIn('slug', $duplicateSlugs)->get();

        if (! $targetRole) {
            $values = [
                'name' => $targetName,
                'slug' => $targetSlug,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('roles', 'organization_id')) {
                $values['organization_id'] = $duplicateRoles->first()?->organization_id;
            }

            if (Schema::hasColumn('roles', 'deleted_at')) {
                $values['deleted_at'] = null;
            }

            $targetId = DB::table('roles')->insertGetId($values);
        } else {
            $targetId = $targetRole->id;
            DB::table('roles')
                ->where('id', $targetId)
                ->update(array_filter([
                    'name' => $targetName,
                    'updated_at' => $now,
                    'deleted_at' => Schema::hasColumn('roles', 'deleted_at') ? null : false,
                ], fn ($value) => $value !== false));
        }

        $duplicateIds = $duplicateRoles
            ->where('id', '!=', $targetId)
            ->pluck('id')
            ->all();

        if ($duplicateIds === []) {
            return;
        }

        if (Schema::hasTable('permission_role')) {
            $permissionIds = DB::table('permission_role')
                ->whereIn('role_id', array_merge([$targetId], $duplicateIds))
                ->pluck('permission_id')
                ->unique()
                ->values();

            $permissionIds->each(fn (int $permissionId) => DB::table('permission_role')->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $targetId,
            ]));
        }

        DB::table('users')
            ->whereIn('role_id', $duplicateIds)
            ->update(['role_id' => $targetId, 'updated_at' => $now]);

        if (Schema::hasColumn('roles', 'deleted_at')) {
            DB::table('roles')
                ->whereIn('id', $duplicateIds)
                ->update(['deleted_at' => $now, 'updated_at' => $now]);
        }
    }
};
