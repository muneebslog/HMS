<?php

namespace Database\Factories;

use App\Enums\UltrasoundBiophysicalProfile;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\ServiceQueue;
use App\Models\UltrasoundReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UltrasoundReport>
 */
class UltrasoundReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'queue_token_id' => QueueToken::factory(),
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory(),
            'service_queue_id' => ServiceQueue::factory(),
            'report_date' => now()->toDateString(),
            'name' => fake()->name(),
            'age' => fake()->numberBetween(18, 60),
            'fetus_status' => fake()->randomElement(['intrauterine', 'extrauterine']),
            'bpd_meas' => (string) fake()->randomFloat(1, 20, 100),
            'bpd_age' => (string) fake()->numberBetween(10, 40),
            'femur_meas' => (string) fake()->randomFloat(1, 20, 100),
            'femur_age' => (string) fake()->numberBetween(10, 40),
            'ac_meas' => (string) fake()->randomFloat(1, 50, 300),
            'ac_age' => (string) fake()->numberBetween(10, 40),
            'crl_meas' => (string) fake()->randomFloat(1, 10, 90),
            'crl_age' => (string) fake()->numberBetween(10, 40),
            'gest_age' => (string) fake()->numberBetween(10, 40),
            'edd' => fake()->date(),
            'heart_motion' => fake()->word(),
            'placenta' => fake()->word(),
            'placenta_grade' => fake()->word(),
            'amniotic_fluid' => fake()->word(),
            'presentation' => fake()->word(),
            'lt_ventricular' => fake()->boolean(),
            'bpd_level' => fake()->boolean(),
            'feral_stomach' => fake()->boolean(),
            'kidneys' => fake()->boolean(),
            'bladder' => fake()->boolean(),
            'spine' => fake()->boolean(),
            'bpp' => fake()->randomElement(UltrasoundBiophysicalProfile::values()),
            'conclusion_line1' => fake()->sentence(),
            'conclusion_line2' => fake()->sentence(),
        ];
    }
}
