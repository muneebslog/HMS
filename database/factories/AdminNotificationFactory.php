<?php

namespace Database\Factories;

use App\Models\AdminNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminNotification>
 */
class AdminNotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'reservation_without_phone',
            'title' => $this->faker->sentence(),
            'message' => $this->faker->paragraph(),
            'read_at' => null,
            'actionable_url' => null,
            'metadata' => null,
        ];
    }

    /**
     * Indicate that the notification has been read.
     */
    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
        ]);
    }
}
