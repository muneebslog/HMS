<?php

namespace Database\Factories;

use App\Enums\DutyAssignmentStatus;
use App\Enums\DutyAssignmentType;
use App\Models\DutyAssignment;
use App\Models\DutyLocation;
use App\Models\DutyShiftTemplate;
use App\Models\HealthAide;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<DutyAssignment>
 */
class DutyAssignmentFactory extends Factory
{
    protected $model = DutyAssignment::class;

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
            'duty_shift_template_id' => DutyShiftTemplate::factory(),
            'duty_location_id' => DutyLocation::factory(),
            'date' => $date,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'assignment_type' => DutyAssignmentType::Regular,
            'status' => DutyAssignmentStatus::Scheduled,
            'created_by' => User::factory()->admin(),
        ];
    }
}
