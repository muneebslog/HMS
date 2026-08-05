<?php

namespace Database\Factories;

use App\Enums\ProcedureMedicationForm;
use App\Enums\ProcedureMedicationScheduleType;
use App\Models\Procedure;
use App\Models\ProcedureMedication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureMedication>
 */
class ProcedureMedicationFactory extends Factory
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
            'form' => ProcedureMedicationForm::Tab,
            'medicine_id' => null,
            'injection_id' => null,
            'drip_base_id' => null,
            'custom_name' => $this->faker->words(2, true),
            'dose' => '1 tab',
            'route' => 'Oral',
            'notes' => null,
            'schedule_type' => ProcedureMedicationScheduleType::OnceNow,
            'schedule_times' => null,
            'interval_hours' => null,
            'starts_at' => now(),
            'ends_at' => null,
            'status' => 'active',
            'prescribed_by' => User::factory(),
        ];
    }
}
