<?php

namespace Database\Factories;

use App\Models\AttendanceDuty;
use App\Models\DutySession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceDuty>
 */
class AttendanceDutyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->subDay()->setTime(8, 0);

        return [
            'duty_session_id' => DutySession::factory(),
            'shift_template_id' => null,
            'scheduled_start' => $start,
            'scheduled_end' => $start->copy()->addHours(7),
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'sort_order' => 0,
        ];
    }
}
