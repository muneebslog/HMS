<?php

namespace Database\Factories;

use App\Enums\EmergencyDepartmentShift;
use App\Models\EmergencyDepartmentLogEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmergencyDepartmentLogEntry>
 */
class EmergencyDepartmentLogEntryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<EmergencyDepartmentLogEntry>
     */
    protected $model = EmergencyDepartmentLogEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->inchargeNurse(),
            'checklist_date' => now()->toDateString(),
            'shift' => EmergencyDepartmentShift::Morning,
            'completed_by_name' => fake()->name(),
            'supervisor_name' => fake()->optional()->name(),
            'equipment_issues_log' => null,
            'submitted_at' => now(),
        ];
    }
}
