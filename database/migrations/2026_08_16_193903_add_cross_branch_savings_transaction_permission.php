<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permission = [
            'name' => 'Transact Savings Across Branches',
            'slug' => 'transact-savings-across-branches',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('permissions', 'organization_id')) {
            $permission['organization_id'] = null;
        }

        DB::table('permissions')->updateOrInsert(
            ['slug' => 'transact-savings-across-branches'],
            $permission,
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('slug', 'transact-savings-across-branches')->delete();
        }
    }
};
