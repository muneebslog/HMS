<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $openedAt = fake()->dateTimeBetween('-1 month', 'now');

        return [
            'user_id' => User::factory(),
            'opened_at' => $openedAt,
            'closed_at' => now(),
            'opening_balance' => fake()->randomFloat(2, 0, 1000),
            'closing_balance' => fake()->randomFloat(2, 0, 1000),
            'status' => 'closed',
        ];
    }

    /**
     * Indicate that the shift is open.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_at' => null,
            'closing_balance' => null,
            'status' => 'open',
        ]);
    }

    /**
     * Indicate that the shift is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_at' => now(),
            'closing_balance' => fake()->randomFloat(2, 0, 1000),
            'status' => 'closed',
        ]);
    }
}
