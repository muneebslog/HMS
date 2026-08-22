<?php

namespace Database\Factories;

use App\Models\AttendanceAdjustment;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceAdjustment>
 */
class AttendanceAdjustmentFactory extends Factory
{
    protected $model = AttendanceAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attendance_record_id' => AttendanceRecord::factory(),
            'field_changed' => 'first_in_at',
            'old_value' => null,
            'new_value' => now()->toDateTimeString(),
            'reason' => 'Manual correction',
            'created_by' => User::factory()->admin(),
        ];
    }
}
