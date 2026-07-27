<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'designation' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Administration', 'Nursing', 'Laboratory', 'Radiology', 'Reception', 'Management']),
            'joining_date' => fake()->optional()->date(),
            'employment_type' => fake()->randomElement(['full_time', 'part_time', 'intern', 'consultant']),
            'status' => 'active',
            'notes' => fake()->optional()->paragraph(),
            'user_id' => null,
            'doctor_id' => null,
            'created_by' => User::factory()->admin(),
        ];
    }

    /**
     * Indicate that the employee is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
