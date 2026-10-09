<?php

namespace Database\Factories;

use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendancePunch>
 */
class AttendancePunchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attendance_device_user_id' => AttendanceDeviceUser::factory(),
            'attendance_device_id' => fn (array $attributes) => AttendanceDeviceUser::query()->find($attributes['attendance_device_user_id'])?->attendance_device_id,
            'device_user_id' => fn (array $attributes) => AttendanceDeviceUser::query()->find($attributes['attendance_device_user_id'])?->device_user_id,
            'punched_at' => fake()->dateTimeBetween('-7 days'),
            'verify_type' => 1,
            'punch_state' => 0,
        ];
    }
}
