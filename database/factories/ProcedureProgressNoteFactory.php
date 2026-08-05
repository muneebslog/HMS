<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\ProcedureProgressNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureProgressNote>
 */
class ProcedureProgressNoteFactory extends Factory
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
            'noted_at' => now(),
            'note' => $this->faker->paragraph(),
            'doctor_user_id' => User::factory(),
        ];
    }
}
