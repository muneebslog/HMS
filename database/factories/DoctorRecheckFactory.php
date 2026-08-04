<?php

namespace Database\Factories;

use App\Models\DoctorRecheck;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorRecheck>
 */
class DoctorRecheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $minutes = fake()->numberBetween(5, 60);

        return [
            'queue_token_id' => QueueToken::factory(),
            'patient_id' => Patient::factory(),
            'set_by' => User::factory(),
            'minutes' => $minutes,
            'note' => fake()->optional()->sentence(3),
            'due_at' => now()->addMinutes($minutes),
            'notified_at' => null,
            'acknowledged_at' => null,
            'vitals_redone_at' => null,
        ];
    }

    /**
     * Indicate that the recheck timer has elapsed.
     */
    public function due(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_at' => now()->subMinute(),
            'notified_at' => null,
            'acknowledged_at' => null,
            'vitals_redone_at' => null,
        ]);
    }

    /**
     * Indicate that the doctor has been notified.
     */
    public function notified(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_at' => now()->subMinute(),
            'notified_at' => now(),
            'acknowledged_at' => null,
            'vitals_redone_at' => null,
        ]);
    }

    /**
     * Indicate that vitals were re-recorded after the timer.
     */
    public function vitalsRedone(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_at' => now()->subMinutes(10),
            'notified_at' => now()->subMinutes(9),
            'acknowledged_at' => null,
            'vitals_redone_at' => now()->subMinute(),
        ]);
    }

    /**
     * Indicate that the recheck has been acknowledged.
     */
    public function acknowledged(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_at' => now()->subMinute(),
            'notified_at' => now()->subMinute(),
            'acknowledged_at' => now(),
        ]);
    }
}
