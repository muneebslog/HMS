<?php

namespace Database\Factories;

use App\Enums\ProcedureMedicationDoseStatus;
use App\Models\ProcedureMedication;
use App\Models\ProcedureMedicationDose;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureMedicationDose>
 */
class ProcedureMedicationDoseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'procedure_medication_id' => ProcedureMedication::factory(),
            'due_at' => now(),
            'status' => ProcedureMedicationDoseStatus::Pending,
            'given_at' => null,
            'given_by' => null,
            'notes' => null,
        ];
    }
}
