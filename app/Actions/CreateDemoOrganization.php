<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\FinancialDefaultsService;
use App\Services\ModuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateDemoOrganization
{
    public function __construct(
        private ModuleService $modules,
        private FinancialDefaultsService $financialDefaults,
    ) {}

    /**
     * @param  array{organization_name:string,name:string,email:string,phone:string,password:string}  $data
     * @return array{organization:Organization,user:User}
     */
    public function handle(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $organization = $this->createOrganization($data);
            $this->financialDefaults->provisionOrganization($organization);
            $branch = $this->createBranch($organization);
            $role = $this->organizationAdminRole($organization);
            $user = User::create([
                'organization_id' => $organization->id,
                'branch_id' => $branch->id,
                'role_id' => $role->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'position' => 'Demo Administrator',
                'status' => 'active',
                'is_active' => true,
                'password' => $data['password'],
            ]);

            $this->enableAllModules($organization, $user);
            SystemSetting::ensureForOrganization($organization);

            return ['organization' => $organization, 'user' => $user];
        });
    }

    /**
     * @param  array{organization_name:string,email:string,phone:string}  $data
     */
    private function createOrganization(array $data): Organization
    {
        $startedAt = now();

        return Organization::create([
            'name' => $data['organization_name'],
            'slug' => Str::slug($data['organization_name']).'-demo-'.Str::lower(Str::random(6)),
            'email' => $data['email'],
            'phone' => $data['phone'],
            'currency' => 'UGX',
            'timezone' => 'Africa/Kampala',
            'status' => 'active',
            'is_demo' => true,
            'demo_started_at' => $startedAt,
            'demo_expires_at' => $startedAt->copy()->addDays((int) config('demo.trial_days', 14)),
        ]);
    }

    private function createBranch(Organization $organization): Branch
    {
        return Branch::create([
            'organization_id' => $organization->id,
            'code' => 'DM-'.Str::upper(Str::random(8)),
            'name' => 'Main Branch',
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    private function organizationAdminRole(Organization $organization): Role
    {
        $permissionIds = collect(config('demo.permissions', []))->map(function (string $slug) use ($organization): int {
            return Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['organization_id' => $organization->id, 'name' => str($slug)->headline()],
            )->id;
        });

        $role = Role::query()->firstOrCreate(
            ['slug' => 'organization-admin'],
            ['organization_id' => $organization->id, 'name' => 'Organization Admin'],
        );
        $role->permissions()->syncWithoutDetaching($permissionIds);

        return $role;
    }

    private function enableAllModules(Organization $organization, User $user): void
    {
        $this->modules->syncCatalog();
        $package = Package::query()->where('name', 'Full MFI Package')->firstOrFail();
        $this->modules->assignPackage($organization, $package, $user);
    }
}
