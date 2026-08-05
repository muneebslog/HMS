<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\ProcedureDischargeDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureDischargeDetail>
 */
class ProcedureDischargeDetailFactory extends Factory
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
            'blood_group' => $this->faker->randomElement(['A+', 'B+', 'O+', 'AB+']),
            'indication' => $this->faker->sentence(),
            'procedure_time' => now(),
            'parity' => null,
            'baby_sex' => null,
            'baby_weight' => null,
            'baby_condition' => null,
            'rx_text' => $this->faker->paragraph(),
            'stitch_removal_date' => now()->addDays(7)->format('Y-m-d'),
            'outcome_summary' => $this->faker->sentence(),
        ];
    }
}
