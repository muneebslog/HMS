<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\DoctorPayout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorPayout>
 */
class DoctorPayoutFactory extends Factory
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
            'date' => now()->toDateString(),
            'from_date' => now()->toDateString(),
            'to_date' => now()->toDateString(),
            'total_amount' => fake()->randomFloat(2, 100, 5000),
            'share_amount' => fake()->randomFloat(2, 10, 1000),
            'paid_at' => now(),
            'created_by' => User::factory(),
        ];
    }
}
