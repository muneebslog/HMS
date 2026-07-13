<?php

namespace Database\Factories;

use App\Models\InvoiceItem;
use App\Models\QueueToken;
use App\Models\ServiceQueue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueueToken>
 */
class QueueTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_queue_id' => ServiceQueue::factory(),
            'invoice_item_id' => InvoiceItem::factory(),
            'patient_id' => null,
            'token_number' => fake()->randomNumber(2),
            'status' => 'waiting',
            'origin' => 'walk_in',
        ];
    }
}
