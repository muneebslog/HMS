<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeLeave>
 */
class EmployeeLeaveFactory extends Factory
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
            'leave_date' => fake()->date(),
            'replacement_employee_id' => null,
            'duty_start_time' => null,
            'duty_end_time' => null,
            'is_informed' => false,
            'informed_by' => null,
            'notes' => null,
            'created_by' => User::factory()->admin(),
        ];
    }

    /**
     * Indicate that the leave has been informed.
     */
    public function informed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_informed' => true,
            'informed_by' => fake()->name(),
        ]);
    }
}
