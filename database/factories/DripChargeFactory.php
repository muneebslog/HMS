<?php

namespace Database\Factories;

use App\Enums\DripChargeStatus;
use App\Models\Doctor;
use App\Models\DripCharge;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DripCharge>
 */
class DripChargeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'queue_token_id' => null,
            'medication_order_id' => null,
            'service_id' => Service::factory()->state(['is_drip' => true]),
            'doctor_id' => Doctor::factory(),
            'suggested_price' => fake()->randomFloat(2, 100, 2000),
            'doctor_share' => fake()->optional()->randomFloat(2, 10, 50),
            'status' => DripChargeStatus::Pending,
            'invoice_id' => null,
            'suggested_by' => User::factory(),
            'paid_by' => null,
            'paid_at' => null,
        ];
    }

    /**
     * Indicate that the charge has been paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DripChargeStatus::Paid,
            'paid_at' => now(),
            'paid_by' => User::factory(),
        ]);
    }
}
