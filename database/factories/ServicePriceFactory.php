<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\Service;
use App\Models\ServicePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServicePrice>
 */
class ServicePriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'doctor_id' => fake()->optional(0.8)->passthrough(Doctor::factory()->create()->id),
            'price' => fake()->randomFloat(2, 10, 1000),
            'doctor_share' => fake()->optional()->randomFloat(2, 0, 100),
        ];
    }
}
