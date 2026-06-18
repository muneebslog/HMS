<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
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
            'phone' => fake()->optional()->phoneNumber(),
            'age' => fake()->optional()->numberBetween(1, 100),
            'gender' => fake()->optional()->randomElement(['male', 'female', 'other']),
        ];
    }
}
