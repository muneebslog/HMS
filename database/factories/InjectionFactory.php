<?php

namespace Database\Factories;

use App\Models\Injection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Injection>
 */
class InjectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Injection',
            'short_form' => null,
            'default_volume_ml' => fake()->randomElement([1, 2, 5, 10]),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the injection is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
