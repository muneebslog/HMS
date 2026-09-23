<?php

namespace Database\Factories;

use App\Enums\FinanceShiftPeriod;
use App\Models\FinanceCashEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceCashEntry>
 */
class FinanceCashEntryFactory extends Factory
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
            'entry_date' => fake()->date(),
            'period' => fake()->randomElement(FinanceShiftPeriod::cases()),
            'amount_collected' => fake()->randomFloat(2, 100, 100000),
            'amount_short' => fake()->randomFloat(2, 0, 5000),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
