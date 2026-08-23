<?php

namespace Database\Factories;

use App\Enums\WorkSessionStatus;
use App\Models\AttendancePunch;
use App\Models\AttendanceWorkSession;
use App\Models\HealthAide;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceWorkSession>
 */
class AttendanceWorkSessionFactory extends Factory
{
    protected $model = AttendanceWorkSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $aide = HealthAide::factory();
        $startsAt = now()->subHours(2);

        return [
            'health_aide_id' => $aide,
            'in_punch_id' => AttendancePunch::factory()->checkIn($startsAt),
            'out_punch_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => null,
            'status' => WorkSessionStatus::Open,
            'duty_assignment_id' => null,
            'worked_minutes' => 0,
            'late_minutes' => 0,
        ];
    }

    public function suggested(): static
    {
        return $this->state(function (array $attributes) {
            $endsAt = ($attributes['starts_at'] ?? now())->copy()->addHours(8);

            return [
                'out_punch_id' => AttendancePunch::factory()->checkOut($endsAt),
                'ends_at' => $endsAt,
                'status' => WorkSessionStatus::Suggested,
                'worked_minutes' => 480,
            ];
        });
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'out_punch_id' => null,
            'ends_at' => null,
            'status' => WorkSessionStatus::Open,
            'worked_minutes' => 0,
        ]);
    }

    public function confirmed(): static
    {
        return $this->suggested()->state(fn () => [
            'status' => WorkSessionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }
}
