<?php

namespace Database\Factories;

use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Station PC '.fake()->unique()->numberBetween(1, 999),
            'location' => fake()->randomElement(['ER Bay', 'Drip Room', 'Ground Floor', 'First Floor']),
            'allowed_pages' => ['display.er', 'display.drips'],
            'is_active' => true,
        ];
    }

    /**
     * Indicate a PC has been registered to the station.
     */
    public function registered(): static
    {
        return $this->state(fn (array $attributes) => [
            'device_token_hash' => hash('sha256', Str::random(64)),
            'registered_at' => now(),
        ]);
    }

    /**
     * Indicate the station PC checked in just now.
     */
    public function online(): static
    {
        return $this->registered()->state(fn (array $attributes) => [
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Indicate the station is disabled.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
