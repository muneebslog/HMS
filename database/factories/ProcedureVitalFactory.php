<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\ProcedureVital;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureVital>
 */
class ProcedureVitalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'procedure_id' => Procedure::factory(),
            'recorded_at' => now(),
            'pulse' => $this->faker->numberBetween(60, 100),
            'bp_systolic' => $this->faker->numberBetween(100, 140),
            'bp_diastolic' => $this->faker->numberBetween(60, 90),
            'resp_rate' => $this->faker->numberBetween(12, 20),
            'temp' => $this->faker->randomFloat(1, 97, 100),
            'cvp' => null,
            'iv_fluid' => null,
            'oral_ng' => null,
            'urine' => null,
            'stool' => null,
            'aspirate' => null,
            'drain' => null,
            'notes' => null,
            'recorded_by' => User::factory(),
        ];
    }
}
