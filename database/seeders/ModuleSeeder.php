<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Organization;
use App\Services\ModuleService;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = app(ModuleService::class);
        $modules->syncCatalog();

        Organization::query()->get()->each(function (Organization $organization) use ($modules): void {
            $modules->syncDefaults($organization);

            Module::query()
                ->where('is_core', false)
                ->whereIn('slug', ['savings', 'loans', 'investments', 'shares', 'fixed-deposits', 'group-savings', 'teller', 'accounting', 'mobile-money', 'sms', 'documents', 'api-access'])
                ->get()
                ->each(fn (Module $module) => $organization->modules()->syncWithoutDetaching([
                    $module->id => ['is_enabled' => true, 'enabled_at' => now()],
                ]));

            $modules->clearCache($organization);
        });
    }
}
