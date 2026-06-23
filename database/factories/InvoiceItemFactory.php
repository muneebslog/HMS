<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $service = Service::factory()->create();
        $doctor = fake()->optional(0.8) ? Doctor::factory()->create() : null;

        return [
            'invoice_id' => Invoice::factory(),
            'service_id' => $service->id,
            'doctor_id' => $doctor?->id,
            'service_name' => $service->name,
            'doctor_name' => $doctor?->name,
            'price' => fake()->randomFloat(2, 10, 1000),
        ];
    }
}
