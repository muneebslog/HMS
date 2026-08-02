<?php

namespace Database\Factories;

use App\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Family>
 */
class FamilyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => fake()->unique()->numerify('03#########'),
        ];
    }

    /**
     * Indicate that the family has no phone number.
     */
    public function withoutPhone(): static
    {
        return $this->state(fn (array $attributes): array => [
            'phone' => null,
        ]);
    }
}
