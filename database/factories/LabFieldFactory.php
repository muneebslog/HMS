<?php

namespace Database\Factories;

use App\Enums\LabFieldType;
use App\Models\LabField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabField>
 */
class LabFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'unit' => fake()->randomElement(['g/dL', 'mg/dL', 'x10^3/uL', 'mmol/L', '%']),
            'type' => LabFieldType::Numeric,
            'options' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the lab field takes free text.
     */
    public function text(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LabFieldType::Text,
            'unit' => null,
        ]);
    }

    /**
     * Indicate that the lab field is picked from a list of options.
     *
     * @param  list<string>  $options
     */
    public function choice(array $options = ['Positive', 'Negative']): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LabFieldType::Choice,
            'unit' => null,
            'options' => $options,
        ]);
    }

    /**
     * Indicate that the lab field is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
