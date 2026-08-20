<?php

namespace Database\Factories;

use App\Enums\WardMaintenanceShift;
use App\Models\User;
use App\Models\WardMaintenanceEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WardMaintenanceEntry>
 */
class WardMaintenanceEntryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<WardMaintenanceEntry>
     */
    protected $model = WardMaintenanceEntry::class;

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
            'shift' => WardMaintenanceShift::Morning,
            'checked_by_name' => fake()->name(),
            'supervisor_name' => fake()->optional()->name(),
            'checked_by_time' => now()->format('H:i'),
            'supervisor_time' => null,
            'patient_safety_fault' => 'no',
            'patient_safety_reported' => 'na',
            'room_unavailable' => 'no',
            'beds_out_of_service' => null,
            'reason_remarks' => null,
            'supervisor_remarks' => null,
            'submitted_at' => now(),
        ];
    }
}
