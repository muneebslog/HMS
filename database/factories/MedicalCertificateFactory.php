<?php

namespace Database\Factories;

use App\Enums\MedicalCertificateType;
use App\Models\Doctor;
use App\Models\MedicalCertificate;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalCertificate>
 */
class MedicalCertificateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => MedicalCertificateType::SickLeave,
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory(),
            'patient_title' => 'Mr.',
            'patient_name' => fake()->name('male'),
            'gender' => 'male',
            'relation' => 's/o',
            'guardian_name' => fake()->name('male'),
            'age' => fake()->numberBetween(16, 60),
            'mrn' => null,
            'cnic' => null,
            'diagnosis' => 'Enteric Fever',
            'show_diagnosis' => true,
            'start_date' => today(),
            'end_date' => today()->addDays(2),
            'doctor_name' => 'Dr. '.fake()->name(),
            'issued_by' => User::factory(),
            'issued_at' => now(),
        ];
    }

    /**
     * Indicate that the certificate is for a married female patient.
     */
    public function female(): static
    {
        return $this->state(fn (array $attributes) => [
            'patient_title' => 'Mrs.',
            'patient_name' => fake()->name('female'),
            'gender' => 'female',
            'relation' => 'w/o',
        ]);
    }

    /**
     * Indicate that the certificate has been voided.
     */
    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'voided_at' => now(),
            'void_reason' => 'Issued by mistake',
        ]);
    }
}
