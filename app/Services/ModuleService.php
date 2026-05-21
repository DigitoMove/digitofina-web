<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Package;
use App\Models\User;
use App\Support\ModuleCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ModuleService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function isEnabled(?Organization $organization, string $slug): bool
    {
        if (! $organization) {
            return true;
        }

        return in_array($slug, $this->enabledSlugs($organization), true);
    }

    /**
     * @return array<int, string>
     */
    public function enabledSlugs(Organization $organization): array
    {
        return Cache::remember($this->cacheKey($organization->id), now()->addMinutes(30), function () use ($organization): array {
            $this->syncDefaults($organization);

            return $organization->modules()
                ->wherePivot('is_enabled', true)
                ->where('status', 'active')
                ->pluck('slug')
                ->all();
        });
    }

    public function syncCatalog(): void
    {
        foreach (ModuleCatalog::modules() as $moduleData) {
            Module::updateOrCreate(['slug' => $moduleData['slug']], $moduleData + ['status' => 'active']);
        }

        foreach (ModuleCatalog::dependencies() as $moduleSlug => $dependencySlugs) {
            $module = Module::where('slug', $moduleSlug)->first();

            if (! $module) {
                continue;
            }

            foreach ($dependencySlugs as $dependencySlug) {
                $dependency = Module::where('slug', $dependencySlug)->first();

                if ($dependency) {
                    $module->dependencies()->syncWithoutDetaching([$dependency->id]);
                }
            }
        }

        foreach (ModuleCatalog::packages() as $slug => $packageData) {
            $package = Package::updateOrCreate(['name' => $packageData['name']], [
                'description' => $packageData['description'],
                'price' => $packageData['price'],
                'billing_type' => $packageData['billing_type'],
                'status' => 'active',
            ]);
            $moduleIds = Module::whereIn('slug', $packageData['modules'])->pluck('id')->all();
            $package->modules()->sync($moduleIds);
        }
    }

    public function syncDefaults(Organization $organization): void
    {
        if (! Module::exists()) {
            $this->syncCatalog();
        }

        Module::query()->where('is_core', true)->get()->each(function (Module $module) use ($organization): void {
            OrganizationModule::firstOrCreate(
                ['organization_id' => $organization->id, 'module_id' => $module->id],
                ['is_enabled' => true, 'enabled_at' => now()],
            );
        });
    }

    /**
     * @return Collection<int, Module>
     */
    public function modulesForOrganization(Organization $organization): Collection
    {
        $this->syncDefaults($organization);

        return Module::query()
            ->with(['dependencies', 'dependents'])
            ->orderBy('sort_order')
            ->get()
            ->map(function (Module $module) use ($organization): Module {
                $pivot = OrganizationModule::firstOrCreate(
                    ['organization_id' => $organization->id, 'module_id' => $module->id],
                    ['is_enabled' => $module->is_core, 'enabled_at' => $module->is_core ? now() : null],
                );
                $module->setRelation('organizationSetting', $pivot);

                return $module;
            });
    }

    public function enable(Organization $organization, Module $module, User $user, ?array $settings = null, ?string $reason = null): OrganizationModule
    {
        return DB::transaction(function () use ($organization, $module, $user, $settings, $reason): OrganizationModule {
            $enabled = collect($this->enabledSlugs($organization));
            $dependencyWarning = $this->dependencyWarning($module, $enabled);

            if ($dependencyWarning) {
                throw new InvalidArgumentException($dependencyWarning);
            }

            $existing = OrganizationModule::where('organization_id', $organization->id)
                ->where('module_id', $module->id)
                ->first();
            $oldValues = $existing?->only(['is_enabled', 'settings', 'enabled_by', 'enabled_at', 'disabled_by', 'disabled_at']) ?? ['is_enabled' => false];

            $record = OrganizationModule::updateOrCreate(
                ['organization_id' => $organization->id, 'module_id' => $module->id],
                [
                    'is_enabled' => true,
                    'enabled_by' => $user->id,
                    'enabled_at' => now(),
                    'disabled_by' => null,
                    'disabled_at' => null,
                    'settings' => $settings,
                ],
            );

            $this->clearCache($organization);
            $this->auditLogger->record(
                'module.enabled',
                $record,
                ['reason' => $reason, 'module' => $module->slug, 'is_enabled' => true],
                $oldValues,
                $user,
            );

            return $record;
        });
    }

    public function disable(Organization $organization, Module $module, User $user, ?string $reason = null): OrganizationModule
    {
        return DB::transaction(function () use ($organization, $module, $user, $reason): OrganizationModule {
            if ($module->is_core) {
                throw new InvalidArgumentException('Core modules cannot be disabled.');
            }

            $enabledAfterDisable = collect($this->enabledSlugs($organization))->reject(fn (string $slug): bool => $slug === $module->slug);
            $blocking = $module->dependents->filter(function (Module $dependent) use ($enabledAfterDisable): bool {
                return $enabledAfterDisable->contains($dependent->slug)
                    && $this->dependencyWarning($dependent->loadMissing('dependencies'), $enabledAfterDisable) !== null;
            });

            if ($blocking->isNotEmpty()) {
                throw new InvalidArgumentException('Disable dependent modules first: '.$blocking->pluck('name')->join(', '));
            }

            $existing = OrganizationModule::where('organization_id', $organization->id)
                ->where('module_id', $module->id)
                ->first();
            $oldValues = $existing?->only(['is_enabled', 'settings', 'enabled_by', 'enabled_at', 'disabled_by', 'disabled_at']) ?? ['is_enabled' => true];

            $record = OrganizationModule::updateOrCreate(
                ['organization_id' => $organization->id, 'module_id' => $module->id],
                [
                    'is_enabled' => false,
                    'disabled_by' => $user->id,
                    'disabled_at' => now(),
                ],
            );

            $this->clearCache($organization);
            $this->auditLogger->record(
                'module.disabled',
                $record,
                ['reason' => $reason, 'module' => $module->slug, 'is_enabled' => false],
                $oldValues,
                $user,
            );

            return $record;
        });
    }

    public function assignPackage(Organization $organization, Package $package, User $user): void
    {
        DB::transaction(function () use ($organization, $package, $user): void {
            $organization->update(['package_id' => $package->id]);

            foreach ($package->modules()->with('dependencies')->get() as $module) {
                OrganizationModule::updateOrCreate(
                    ['organization_id' => $organization->id, 'module_id' => $module->id],
                    ['is_enabled' => true, 'enabled_by' => $user->id, 'enabled_at' => now(), 'disabled_by' => null, 'disabled_at' => null],
                );
            }

            $this->clearCache($organization);
            $this->auditLogger->record('module.package_assigned', $organization, ['package' => $package->name]);
        });
    }

    public function clearCache(Organization $organization): void
    {
        Cache::forget($this->cacheKey($organization->id));
    }

    private function cacheKey(int $organizationId): string
    {
        return "organizations.{$organizationId}.enabled_modules";
    }

    private function dependencyWarning(Module $module, Collection $enabled): ?string
    {
        $orDependencies = match ($module->slug) {
            'teller' => ['savings', 'loans'],
            'mobile-money' => ['savings', 'loans', 'investments'],
            'accounting' => ['savings', 'loans', 'investments', 'shares', 'fixed-deposits'],
            default => [],
        };

        if ($orDependencies !== []) {
            return $enabled->intersect($orDependencies)->isNotEmpty()
                ? null
                : 'Enable at least one required module first: '.Module::whereIn('slug', $orDependencies)->pluck('name')->join(', ');
        }

        $missing = $module->dependencies->filter(fn (Module $dependency): bool => ! $enabled->contains($dependency->slug));

        return $missing->isEmpty()
            ? null
            : 'Enable required module dependencies first: '.$missing->pluck('name')->join(', ');
    }
}
