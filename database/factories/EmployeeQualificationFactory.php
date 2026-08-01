<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeQualification>
 */
class EmployeeQualificationFactory extends Factory
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
            'course' => fake()->randomElement(['MBBS', 'BSc Nursing', 'Diploma in Radiology', 'MSc Laboratory Science']),
            'passing_year' => fake()->numberBetween(1990, (int) date('Y')),
            'institution' => fake()->company().' University',
            'document_path' => null,
            'original_name' => null,
            'created_by' => User::factory()->admin(),
        ];
    }
}
