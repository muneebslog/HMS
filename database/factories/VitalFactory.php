<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vital>
 */
class VitalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'queue_token_id' => QueueToken::factory(),
            'patient_id' => Patient::factory(),
            'recorded_by' => User::factory(),
            'temperature' => fake()->randomFloat(1, 36.0, 39.5),
            'bp_systolic' => fake()->numberBetween(90, 160),
            'bp_diastolic' => fake()->numberBetween(60, 100),
        ];
    }
}
