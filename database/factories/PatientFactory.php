<?php

namespace Database\Factories;

use App\Models\Family;
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
            'family_id' => null,
            'name' => fake()->name(),
            'husband_name' => fake()->optional()->name('male'),
            'cnic' => null,
            'mrn' => null,
            'age' => fake()->optional()->numberBetween(1, 100),
            'gender' => fake()->optional()->randomElement(['male', 'female', 'other']),
        ];
    }

    /**
     * Attach the patient to a family with the given phone number.
     */
    public function withPhone(string $phone): static
    {
        return $this->state(fn (array $attributes): array => [
            'family_id' => Family::factory()->create(['phone' => $phone])->id,
        ]);
    }

    /**
     * Attach the patient to an existing family.
     */
    public function forFamily(Family $family): static
    {
        return $this->state(fn (array $attributes): array => [
            'family_id' => $family->id,
        ]);
    }
}
