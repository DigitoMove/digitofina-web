<?php

namespace Database\Factories;

use App\Models\TrafficVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficVisit>
 */
class TrafficVisitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'host' => 'demo.example.com',
            'domain' => 'example.com',
            'subdomain' => 'demo',
            'visitor_hash' => hash('sha256', fake()->uuid()),
            'started_at' => now(),
            'last_seen_at' => now(),
            'page_views' => 1,
            'active_seconds' => 0,
        ];
    }
}
