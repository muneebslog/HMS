<?php

namespace Database\Factories;

use App\Enums\AttendanceRecordStatus;
use App\Models\AttendanceRecord;
use App\Models\DutyAssignment;
use App\Models\HealthAide;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = Carbon::today();
        $startsAt = $date->copy()->setTime(7, 0);
        $endsAt = $date->copy()->setTime(15, 0);

        return [
            'health_aide_id' => HealthAide::factory(),
            'duty_assignment_id' => DutyAssignment::factory(),
            'date' => $date,
            'scheduled_starts_at' => $startsAt,
            'scheduled_ends_at' => $endsAt,
            'status' => AttendanceRecordStatus::Present,
        ];
    }
}
