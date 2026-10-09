<?php

namespace Database\Factories;

use App\Models\AttendanceDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceDevice>
 */
class AttendanceDeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Main Entrance', 'Staff Gate', 'OT Wing']).' K60',
            'ip_address' => fake()->unique()->localIpv4(),
            'port' => 4370,
            'comm_key' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the device is not synced automatically.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
