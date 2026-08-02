<?php

namespace Database\Factories;

use App\Enums\PrintJobStatus;
use App\Models\PdfPrintJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<PdfPrintJob>
 */
class PdfPrintJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->slug().'.pdf';
        $path = 'pdf-print-jobs/'.fake()->uuid().'.pdf';

        Storage::disk('local')->put($path, '%PDF-1.4 fake pdf content');

        return [
            'user_id' => User::factory()->admin(),
            'original_filename' => $filename,
            'disk_path' => $path,
            'status' => PrintJobStatus::Pending,
            'attempts' => 0,
            'printed_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ];
    }

    /**
     * Mark the PDF print job as pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatus::Pending,
            'printed_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ]);
    }

    /**
     * Mark the PDF print job as printed.
     */
    public function printed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatus::Printed,
            'printed_at' => now(),
            'failed_at' => null,
            'error_message' => null,
        ]);
    }

    /**
     * Mark the PDF print job as failed.
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
