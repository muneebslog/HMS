<?php

namespace Database\Factories;

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceQueue>
 */
class ServiceQueueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'doctor_id' => Doctor::factory(),
            'shift_id' => Shift::factory(),
            'date' => today(),
            'reset_type' => fake()->randomElement(array_column(TokenResetType::cases(), 'value')),
            'opened_at' => now(),
            'closed_at' => null,
            'status' => 'open',
            'last_token_number' => 0,
        ];
    }

    /**
     * Indicate that the queue is open.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_at' => null,
            'status' => 'open',
        ]);
    }

    /**
     * Indicate that the queue is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_at' => now(),
            'status' => 'closed',
        ]);
    }

    /**
     * Indicate that the queue resets per shift.
     */
    public function shiftReset(): static
    {
        return $this->state(fn (array $attributes) => [
            'reset_type' => TokenResetType::Shift->value,
        ]);
    }

    /**
     * Indicate that the queue resets daily.
     */
    public function dailyReset(): static
    {
        return $this->state(fn (array $attributes) => [
            'reset_type' => TokenResetType::Daily->value,
        ]);
    }
}
