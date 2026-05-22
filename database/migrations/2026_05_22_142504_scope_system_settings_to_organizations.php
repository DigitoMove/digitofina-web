<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $originalOrganizationId = DB::table('organizations')->orderBy('id')->value('id');

        if ($originalOrganizationId !== null && Schema::hasColumn('system_settings', 'organization_id')) {
            DB::table('system_settings')
                ->whereNull('organization_id')
                ->update(['organization_id' => $originalOrganizationId]);
        }

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropUnique('system_settings_key_unique');
        });

        if ($originalOrganizationId !== null) {
            $templateSettings = DB::table('system_settings')
                ->where('organization_id', $originalOrganizationId)
                ->orderBy('id')
                ->get();

            DB::table('organizations')
                ->orderBy('id')
                ->pluck('id')
                ->each(function (int $organizationId) use ($templateSettings): void {
                    foreach ($templateSettings as $setting) {
                        $exists = DB::table('system_settings')
                            ->where('organization_id', $organizationId)
                            ->where('key', $setting->key)
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        DB::table('system_settings')->insert([
                            'organization_id' => $organizationId,
                            'key' => $setting->key,
                            'label' => $setting->label ?? null,
                            'value' => $setting->value,
                            'type' => $setting->type,
                            'group' => $setting->group,
                            'description' => $setting->description ?? null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->unique(['organization_id', 'key'], 'system_settings_organization_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropUnique('system_settings_organization_key_unique');
        });

        $originalOrganizationId = DB::table('organizations')->orderBy('id')->value('id');

        if ($originalOrganizationId !== null) {
            DB::table('system_settings')
                ->where('organization_id', '!=', $originalOrganizationId)
                ->delete();

            DB::table('system_settings')
                ->where('organization_id', $originalOrganizationId)
                ->update(['organization_id' => null]);
        }

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->unique('key', 'system_settings_key_unique');
        });
    }
};
