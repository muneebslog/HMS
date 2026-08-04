<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\LabDoctorShare;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabDoctorShare>
 */
class LabDoctorShareFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'share_percent' => fake()->randomFloat(2, 1, 50),
        ];
    }
}
