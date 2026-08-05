<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\ProcedureFetalHeart;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureFetalHeart>
 */
class ProcedureFetalHeartFactory extends Factory
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
            'fhr' => $this->faker->numberBetween(110, 160),
            'notes' => null,
            'recorded_by' => User::factory(),
        ];
    }
}
