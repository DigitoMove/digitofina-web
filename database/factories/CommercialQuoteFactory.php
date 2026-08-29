<?php

namespace Database\Factories;

use App\Models\CommercialQuote;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommercialQuote>
 */
class CommercialQuoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->value('id') ?? Organization::create([
                'name' => fake()->company(),
                'slug' => fake()->unique()->slug(),
            ])->id,
            'requested_by' => User::factory(),
            'quote_number' => 'DFQ-'.fake()->unique()->numerify('############'),
            'billing_type' => 'subscription',
            'subscription_months' => 1,
            'currency' => 'UGX',
            'requested_scope' => fake()->paragraph(),
            'status' => 'requested',
        ];
    }
}
