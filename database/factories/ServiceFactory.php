<?php

namespace Database\Factories;

use App\Enums\TokenResetType;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'is_standalone' => fake()->boolean(),
            'token_reset_type' => fake()->randomElement(array_column(TokenResetType::cases(), 'value')),
        ];
    }
}
