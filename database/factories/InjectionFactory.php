<?php

namespace Database\Factories;

use App\Enums\InjectionAdministrationType;
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
            'default_administration_type' => fake()->randomElement(InjectionAdministrationType::cases()),
            'is_active' => true,
            'stock_quantity' => 100,
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
