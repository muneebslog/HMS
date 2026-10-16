<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeSalary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeSalary>
 */
class EmployeeSalaryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'monthly_salary' => fake()->numberBetween(20, 80) * 1000,
            'effective_from' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'notes' => null,
            'created_by' => null,
        ];
    }
}
