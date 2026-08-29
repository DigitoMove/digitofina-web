<?php

namespace Database\Factories;

use App\Models\CommercialQuote;
use App\Models\OrganizationHostingFee;
use App\Models\OrganizationHostingFee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationHostingFee>
 */
class OrganizationHostingFeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn (array $attributes): int => CommercialQuote::query()->findOrFail($attributes['commercial_quote_id'])->organization_id,
            'commercial_quote_id' => CommercialQuote::factory()->state(['billing_type' => 'hosting', 'amount' => 200000, 'status' => 'issued']),
            'period_starts_on' => today(),
            'period_ends_on' => today()->addYear()->subDay(),
            'due_on' => today()->addDays(30),
            'amount' => 200000,
            'currency' => 'UGX',
            'status' => 'due',
        ];
    }
}
