<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeDocument>
 */
class EmployeeDocumentFactory extends Factory
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
            'title' => fake()->words(3, true),
            'type' => fake()->randomElement(['degree', 'certificate', 'license', 'contract', 'other']),
            'file_path' => 'employee-documents/'.fake()->uuid().'.pdf',
            'original_name' => fake()->words(2, true).'.pdf',
            'notes' => fake()->optional()->sentence(),
            'issue_date' => fake()->optional()->date(),
            'expiry_date' => fake()->optional()->date(),
            'created_by' => User::factory()->admin(),
        ];
    }

    /**
     * Indicate that the document is a license.
     */
    public function license(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'license',
        ]);
    }
}
