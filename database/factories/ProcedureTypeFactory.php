<?php

namespace Database\Factories;

use App\Models\ProcedureType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureType>
 */
class ProcedureTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the procedure type is inactive.
     */
    public function inactive(): self
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
