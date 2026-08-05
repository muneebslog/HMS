<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\ProcedurePreOpOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedurePreOpOrder>
 */
class ProcedurePreOpOrderFactory extends Factory
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
            'give_bath' => false,
            'provide_hospital_dress' => false,
            'npo_from' => null,
            'mark_operation_site' => false,
            'shave_and_prepare' => false,
            'blood_pints' => null,
            'investigations' => null,
            'pre_medication' => null,
            'send_to_ot_at' => null,
            'other_orders' => null,
            'operation_site' => null,
            'done_by' => null,
            'completed_by' => null,
            'completed_at' => null,
        ];
    }
}
