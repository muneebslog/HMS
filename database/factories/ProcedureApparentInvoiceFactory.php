<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\ProcedureApparentInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureApparentInvoice>
 */
class ProcedureApparentInvoiceFactory extends Factory
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
            'total' => $this->faker->randomFloat(2, 10000, 200000),
            'admission_date' => now()->subDays(3)->toDateString(),
            'discharge_date' => now()->subDay()->toDateString(),
            'issued_date' => now()->toDateString(),
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }
}
