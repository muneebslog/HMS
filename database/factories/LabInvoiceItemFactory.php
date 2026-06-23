<?php

namespace Database\Factories;

use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabInvoiceItem>
 */
class LabInvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $labTest = LabTest::factory()->create();

        return [
            'lab_invoice_id' => LabInvoice::factory(),
            'lab_test_id' => $labTest->id,
            'test_name' => $labTest->test_name,
            'test_code' => $labTest->test_code,
            'time_required' => $labTest->time_required,
            'is_in_house' => $labTest->is_in_house,
            'price' => $labTest->test_price,
        ];
    }
}
