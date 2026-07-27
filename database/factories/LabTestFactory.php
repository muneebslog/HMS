<?php

namespace Database\Factories;

use App\Models\LabTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabTest>
 */
class LabTestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'test_name' => fake()->words(3, true),
            'test_code' => fake()->unique()->bothify('LAB-####'),
            'test_price' => fake()->randomFloat(2, 100, 10000),
            'sample' => fake()->optional()->sentence(),
            'time_required' => fake()->randomElement(['30 minutes', '1 hour', '2 hours', '1 day', '3 days']),
            'is_in_house' => fake()->boolean(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the lab test is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
