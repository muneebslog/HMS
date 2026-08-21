<?php

namespace Database\Factories;

use App\Models\DripBase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DripBase>
 */
class DripBaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Normal Saline', 'Ringer Lactate', 'Dextrose 5%']),
            'default_volume_ml' => fake()->randomElement([100, 250, 500, 1000]),
            'show_on_er' => false,
            'is_active' => true,
            'stock_quantity' => 100,
        ];
    }

    /**
     * Indicate that the drip base is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
