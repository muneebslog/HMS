<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
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
            'specialization' => fake()->jobTitle(),
            'payout_daily' => false,
            'duty_start_time' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the doctor is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the doctor is linked to the given user.
     */
    public function forUser(?User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user?->id,
        ]);
    }
}
