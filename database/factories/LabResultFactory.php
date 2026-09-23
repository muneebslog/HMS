<?php

namespace Database\Factories;

use App\Models\LabField;
use App\Models\LabInvoiceItem;
use App\Models\LabResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabResult>
 */
class LabResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lab_invoice_item_id' => LabInvoiceItem::factory(),
            'lab_field_id' => LabField::factory(),
            'value' => (string) fake()->randomFloat(1, 1, 20),
            'entered_by' => null,
        ];
    }
}
