<?php

namespace Database\Factories;

use App\Models\AttendanceDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceDevice>
 */
class AttendanceDeviceFactory extends Factory
{
    protected $model = AttendanceDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Test K60',
            'ip_address' => '192.168.100.201',
            'port' => 4370,
            'is_active' => true,
        ];
    }
}
