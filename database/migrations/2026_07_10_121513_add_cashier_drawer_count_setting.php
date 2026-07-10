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
        $setting = [
            'key' => 'cashier_drawer_count_enabled',
            'label' => 'Cashier drawer denomination count',
            'value' => '0',
            'type' => 'boolean',
            'group' => 'teller',
            'description' => 'Allow teller/cashier users to count UGX notes and coins before closing their drawer.',
        ];

        $organizationIds = Schema::hasTable('organizations')
            ? DB::table('organizations')->orderBy('id')->pluck('id')->all()
            : [];

        $timestamps = [
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('system_settings', 'deleted_at')) {
            $timestamps['deleted_at'] = null;
        }

        foreach ([null, ...$organizationIds] as $organizationId) {
            DB::table('system_settings')->updateOrInsert(
                [
                    'organization_id' => $organizationId,
                    'key' => $setting['key'],
                ],
                $setting + ['organization_id' => $organizationId] + $timestamps,
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'cashier_drawer_count_enabled')
            ->delete();
    }
};
