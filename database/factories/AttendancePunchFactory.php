<?php

namespace Database\Factories;

use App\Enums\PunchStateSource;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\HealthAide;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AttendancePunch>
 */
class AttendancePunchFactory extends Factory
{
    protected $model = AttendancePunch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $punchedAt = Carbon::parse(fake()->dateTimeBetween('-1 day', 'now'));

        return [
            'attendance_device_id' => AttendanceDevice::factory(),
            'device_punch_uid' => fake()->unique()->uuid(),
            'device_user_id' => (string) fake()->numberBetween(1, 999),
            'health_aide_id' => HealthAide::factory(),
            'punched_at' => $punchedAt,
            'verify_type' => 1,
            'punch_state' => 255,
            'punch_state_source' => PunchStateSource::Device,
        ];
    }

    public function checkIn(Carbon|CarbonInterface|null $at = null): static
    {
        return $this->state(fn () => [
            'punched_at' => $at ?? now(),
            'punch_state' => 0,
        ]);
    }

    public function checkOut(Carbon|CarbonInterface|null $at = null): static
    {
        return $this->state(fn () => [
            'punched_at' => $at ?? now(),
            'punch_state' => 1,
        ]);
    }
}
