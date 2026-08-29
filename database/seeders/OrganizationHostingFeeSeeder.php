<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Services\BillingAdministrationService;
use Illuminate\Database\Seeder;

class OrganizationHostingFeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(BillingAdministrationService $billing): void
    {
        Organization::query()->where('status', 'active')->each(fn (Organization $organization) => $billing->ensureCurrentHostingFee($organization));
    }
}
