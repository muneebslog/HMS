<?php

namespace Database\Factories;

use App\Models\HealthAide;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthAide>
 */
class HealthAideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'pin' => (string) fake()->unique()->numerify('####'),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the health aide is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
