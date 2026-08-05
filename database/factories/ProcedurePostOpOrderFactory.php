<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\ProcedurePostOpOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedurePostOpOrder>
 */
class ProcedurePostOpOrderFactory extends Factory
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
            'maintain_intake_output' => false,
            'npo_till' => null,
            'antibiotics' => null,
            'iv_fluids' => null,
            'analgesics' => null,
            'antiemetics' => null,
            'biopsy' => null,
            'others' => null,
            'done_by' => null,
            'completed_by' => null,
            'completed_at' => null,
        ];
    }
}
