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
        $templateOrganizationId = DB::table('organizations')->orderBy('id')->value('id');

        if ($templateOrganizationId === null) {
            return;
        }

        DB::table('system_settings')
            ->where('organization_id', $templateOrganizationId)
            ->orderBy('id')
            ->get()
            ->each(function (object $setting): void {
                $exists = DB::table('system_settings')
                    ->whereNull('organization_id')
                    ->where('key', $setting->key)
                    ->exists();

                if ($exists) {
                    return;
                }

                DB::table('system_settings')->insert([
                    'organization_id' => null,
                    'key' => $setting->key,
                    'label' => $setting->label ?? null,
                    'value' => $setting->value,
                    'type' => $setting->type,
                    'group' => $setting->group,
                    'description' => $setting->description ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
