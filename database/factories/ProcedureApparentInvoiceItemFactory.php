<?php

namespace Database\Factories;

use App\Models\ProcedureApparentInvoice;
use App\Models\ProcedureApparentInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureApparentInvoiceItem>
 */
class ProcedureApparentInvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'procedure_apparent_invoice_id' => ProcedureApparentInvoice::factory(),
            'name' => $this->faker->randomElement(ProcedureApparentInvoice::DEFAULT_FEE_NAMES),
            'amount' => $this->faker->randomFloat(2, 1000, 50000),
            'sort_order' => 0,
        ];
    }
}
