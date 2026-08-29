<?php

namespace Database\Factories;

use App\Models\CommercialQuote;
use App\Models\OrganizationSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationSubscription>
 */
class OrganizationSubscriptionFactory extends Factory
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
            'commercial_quote_id' => CommercialQuote::factory(),
            'billing_type' => 'subscription',
            'status' => 'active',
            'amount' => 200000,
            'currency' => 'UGX',
            'provider' => 'nylon-pay',
            'provider_reference' => fake()->unique()->uuid(),
            'starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
            'activated_at' => now(),
        ];
    }
}
