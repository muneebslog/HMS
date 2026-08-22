<?php

namespace Database\Factories;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceDeviceUser>
 */
class AttendanceDeviceUserFactory extends Factory
{
    protected $model = AttendanceDeviceUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attendance_device_id' => AttendanceDevice::factory(),
            'device_uid' => fake()->unique()->numberBetween(1, 9999),
            'device_user_id' => (string) fake()->unique()->numberBetween(1, 9999),
            'name' => fake()->name(),
            'last_seen_at' => now(),
        ];
    }
}
