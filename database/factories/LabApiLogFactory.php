<?php

namespace Database\Factories;

use App\Enums\LabApiStatus;
use App\Models\LabApiLog;
use App\Models\LabInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabApiLog>
 */
class LabApiLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<LabApiLog>
     */
    protected $model = LabApiLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lab_invoice_id' => LabInvoice::factory(),
            'status' => LabApiStatus::Sent,
            'request_payload' => null,
            'response_body' => null,
            'http_status' => 201,
            'error_message' => null,
            'sent_at' => now(),
            'lab_case_url' => null,
        ];
    }

    /**
     * Mark the log as sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LabApiStatus::Sent,
            'http_status' => 201,
            'error_message' => null,
        ]);
    }

    /**
     * Mark the log as failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LabApiStatus::Failed,
            'http_status' => 500,
            'error_message' => fake()->sentence(),
        ]);
    }

    /**
     * Mark the log as skipped.
     */
    public function skipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LabApiStatus::Skipped,
            'http_status' => null,
            'error_message' => null,
        ]);
    }
}
