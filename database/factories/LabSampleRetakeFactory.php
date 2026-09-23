<?php

namespace Database\Factories;

use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabSampleRetake>
 */
class LabSampleRetakeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lab_invoice_item_id' => LabInvoiceItem::factory()->inHouse(),
            'reason' => fake()->randomElement(LabSampleRetake::REASONS),
            'requested_by' => User::factory(),
        ];
    }

    /**
     * Reception has called the patient.
     */
    public function contacted(): static
    {
        return $this->state(fn (array $attributes) => [
            'patient_contacted_at' => now(),
            'patient_contacted_by' => User::factory(),
        ]);
    }

    /**
     * The patient came back and the retake slip was printed.
     */
    public function slipPrinted(): static
    {
        return $this->contacted()->state(fn (array $attributes) => [
            'slip_printed_at' => now(),
            'slip_printed_by' => User::factory(),
        ]);
    }
}
