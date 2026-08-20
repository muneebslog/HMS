<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedureType;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Procedure>
 */
class ProcedureFactory extends Factory
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
            'procedure_type_id' => ProcedureType::factory(),
            'name' => fn (array $attributes) => ProcedureType::find($attributes['procedure_type_id'])?->name
                ?? $this->faker->words(3, true),
            'expected_delivery_date' => $this->faker->optional()->dateTimeBetween('+1 month', '+9 months')?->format('Y-m-d'),
            'full_amount' => $this->faker->randomFloat(2, 100, 5000),
            'room_number' => null,
            'doctor_id' => null,
            'created_by' => User::factory(),
            'shift_id' => Shift::factory(),
        ];
    }

    /**
     * Mark the procedure as admitted.
     */
    public function admitted(): self
    {
        return $this->state(fn () => [
            'admitted_at' => now(),
        ]);
    }

    /**
     * Mark the procedure as admitted and discharged.
     */
    public function discharged(): self
    {
        return $this->state(fn () => [
            'admitted_at' => now()->subDay(),
            'discharged_at' => now(),
        ]);
    }

    /**
     * Assign a doctor to the procedure.
     */
    public function withDoctor(): self
    {
        return $this->state(fn () => [
            'doctor_id' => Doctor::factory(),
        ]);
    }
}
