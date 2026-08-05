<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\ProcedureOperationNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureOperationNote>
 */
class ProcedureOperationNoteFactory extends Factory
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
            'operated_on' => now()->format('Y-m-d'),
            'started_at' => '09:00:00',
            'ended_at' => '10:00:00',
            'operation' => $this->faker->words(3, true),
            'surgeon' => $this->faker->name(),
            'nurse' => $this->faker->name(),
            'anaesthesia' => $this->faker->randomElement(['General', 'Spinal', 'Local']),
            'findings' => $this->faker->sentence(),
            'procedure_text' => $this->faker->paragraph(),
            'closure' => $this->faker->sentence(),
            'drain' => null,
            'biopsy' => null,
            'recorded_by' => null,
        ];
    }
}
