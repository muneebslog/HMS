<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeTodo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTodo>
 */
class EmployeeTodoFactory extends Factory
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
            'title' => fake()->sentence(),
            'description' => fake()->optional()->paragraph(),
            'due_date' => fake()->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'completed_at' => null,
            'completed_by' => null,
            'created_by' => User::factory()->admin(),
        ];
    }

    /**
     * Indicate that the todo is overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => fake()->dateTimeBetween('-30 days', '-1 day')->format('Y-m-d'),
        ]);
    }

    /**
     * Indicate that the todo is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => now(),
            'completed_by' => User::factory()->admin(),
        ]);
    }
}
