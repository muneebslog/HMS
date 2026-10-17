<?php

namespace Database\Factories;

use App\Enums\LeaveKind;
use App\Models\Employee;
use App\Models\StaffLeave;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffLeave>
 */
class StaffLeaveFactory extends Factory
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
            'leave_date' => now()->subDays(fake()->numberBetween(1, 20))->toDateString(),
            'kind' => LeaveKind::Informed,
            'days' => 1,
            'paid_days' => 1,
            'force_paid' => false,
            'reason' => null,
            'created_by' => null,
        ];
    }

    /**
     * An absence nobody was told about.
     */
    public function uninformed(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => LeaveKind::Uninformed,
            'paid_days' => 0,
        ]);
    }
}
