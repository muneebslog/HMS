<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeExperience;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeExperience>
 */
class EmployeeExperienceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $joining = fake()->dateTimeBetween('-10 years', '-1 year');
        $leaving = fake()->optional(0.7)->dateTimeBetween($joining, 'now');

        return [
            'employee_id' => Employee::factory(),
            'company' => fake()->company(),
            'date_of_joining' => $joining->format('Y-m-d'),
            'date_of_leaving' => $leaving?->format('Y-m-d'),
            'reason_for_leaving' => $leaving ? fake()->sentence() : null,
        ];
    }
}
