<?php

namespace Database\Factories;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\HealthAide;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceDeviceUser>
 */
class AttendanceDeviceUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uid = fake()->unique()->numberBetween(1, 5000);

        return [
            'attendance_device_id' => AttendanceDevice::factory(),
            'device_uid' => $uid,
            'device_user_id' => (string) $uid,
            'name' => fake()->firstName(),
            'privilege' => 0,
            'card_number' => null,
            'health_aide_id' => null,
            'last_seen_at' => now(),
        ];
    }

    /**
     * Link the device user to a health aide.
     */
    public function linked(?HealthAide $healthAide = null): static
    {
        return $this->state(fn (array $attributes) => [
            'health_aide_id' => $healthAide?->id ?? HealthAide::factory(),
        ]);
    }
}
