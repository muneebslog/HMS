<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory()->create();

        return [
            'patient_id' => Patient::factory(),
            'invoice_number' => fake()->unique()->regexify('INV-[0-9]{10}'),
            'total' => fake()->randomFloat(2, 10, 1000),
            'status' => 'pending',
            'created_by' => $user->id,
            'shift_id' => Shift::factory()->state(['user_id' => $user->id]),
        ];
    }

    /**
     * Mark the invoice as paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
        ]);
    }

    /**
     * Mark the invoice as pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }
}
