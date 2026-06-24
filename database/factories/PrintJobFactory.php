<?php

namespace Database\Factories;

use App\Enums\PrintJobStatus;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\PrintJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintJob>
 */
class PrintJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['invoice', 'lab_invoice']);

        return [
            'invoice_id' => $type === 'invoice' ? Invoice::factory() : null,
            'lab_invoice_id' => $type === 'lab_invoice' ? LabInvoice::factory() : null,
            'status' => PrintJobStatus::Pending,
            'payload' => [
                'type' => $type,
                'title' => 'Invoice Receipt',
            ],
            'attempts' => 0,
            'printed_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ];
    }

    /**
     * Mark the print job as pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatus::Pending,
            'printed_at' => null,
            'failed_at' => null,
        ]);
    }

    /**
     * Mark the print job as printed.
     */
    public function printed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatus::Printed,
            'printed_at' => now(),
            'failed_at' => null,
        ]);
    }

    /**
     * Mark the print job as failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatus::Failed,
            'failed_at' => now(),
            'error_message' => fake()->sentence(),
        ]);
    }
}
