<?php

namespace Database\Factories;

use App\Enums\OutgoingSampleStatus;
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
            'sample' => $labTest->sample,
            'time_required' => $labTest->time_required,
            'is_in_house' => $labTest->is_in_house,
            'outgoing_status' => $labTest->is_in_house ? null : OutgoingSampleStatus::Pending,
            'price' => $labTest->test_price,
        ];
    }

    /**
     * Mark the item as an outgoing sample awaiting pickup call.
     */
    public function outgoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_in_house' => false,
            'outgoing_status' => OutgoingSampleStatus::Pending,
            'lab_result_ready' => null,
        ]);
    }

    /**
     * Mark the item as an in-house test.
     */
    public function inHouse(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_in_house' => true,
            'outgoing_status' => null,
        ]);
    }
}
