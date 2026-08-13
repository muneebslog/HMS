<?php

namespace Database\Factories;

use App\Enums\StationType;
use App\Models\HealthAide;
use App\Models\StationSession;
use App\Services\HealthAidePinSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StationSession>
 */
class StationSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $authenticatedAt = now();

        return [
            'station' => StationType::Er,
            'health_aide_id' => HealthAide::factory(),
            'authenticated_at' => $authenticatedAt,
            'expires_at' => $authenticatedAt->copy()->addMinutes(HealthAidePinSession::TTL_MINUTES),
            'last_seen_at' => $authenticatedAt,
        ];
    }

    /**
     * Indicate the session has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'authenticated_at' => now()->subMinutes(HealthAidePinSession::TTL_MINUTES + 5),
            'expires_at' => now()->subMinutes(5),
            'last_seen_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Indicate no aide is logged in.
     */
    public function empty(): static
    {
        return $this->state(fn (array $attributes) => [
            'health_aide_id' => null,
            'authenticated_at' => null,
            'expires_at' => null,
            'last_seen_at' => null,
        ]);
    }
}
