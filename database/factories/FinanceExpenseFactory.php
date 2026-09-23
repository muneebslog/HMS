<?php

namespace Database\Factories;

use App\Enums\FinanceExpenseCategory;
use App\Models\FinanceExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceExpense>
 */
class FinanceExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = fake()->randomElement(FinanceExpenseCategory::cases());

        return [
            'user_id' => User::factory(),
            'name' => $category === FinanceExpenseCategory::Salary
                ? fake()->randomElement(['Staff Salaries', 'Nurse Salaries', 'Doctor Salaries'])
                : fake()->randomElement(['Electricity', 'Water', 'Rent', 'Maintenance', 'Supplies']),
            'category' => $category,
            'amount' => fake()->randomFloat(2, 100, 50000),
            'expense_date' => fake()->date(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
