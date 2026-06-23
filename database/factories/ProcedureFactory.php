<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Procedure;
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
            'name' => $this->faker->words(3, true),
            'full_amount' => $this->faker->randomFloat(2, 100, 5000),
            'room_number' => $this->faker->bothify('Room ##'),
            'doctor_id' => null,
            'created_by' => User::factory(),
            'shift_id' => Shift::factory(),
        ];
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
