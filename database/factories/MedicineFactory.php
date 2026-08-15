<?php

namespace Database\Factories;

use App\Enums\MedicineDose;
use App\Models\Medicine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medicine>
 */
class MedicineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'short_form' => null,
            'unit' => fake()->randomElement(['tablet', 'syrup', 'capsule']),
            'default_dose' => fake()->randomElement(MedicineDose::cases()),
            'default_days' => fake()->numberBetween(1, 10),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the medicine is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
