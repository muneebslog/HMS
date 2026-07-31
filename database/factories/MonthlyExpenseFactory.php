<?php

namespace Database\Factories;

use App\Models\MonthlyExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyExpense>
 */
class MonthlyExpenseFactory extends Factory
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
            'name' => fake()->randomElement(['Electricity', 'Water', 'Internet', 'Rent', 'Maintenance']),
            'amount' => fake()->randomFloat(2, 100, 50000),
            'expense_date' => fake()->date(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
