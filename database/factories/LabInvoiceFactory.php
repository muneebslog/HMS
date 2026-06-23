<?php

namespace Database\Factories;

use App\Models\LabInvoice;
use App\Models\Patient;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabInvoice>
 */
class LabInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 10000);
        $discountPercentage = fake()->randomFloat(2, 0, 25);
        $discountAmount = round($subtotal * ($discountPercentage / 100), 2);
        $user = User::factory()->create();

        return [
            'patient_id' => Patient::factory(),
            'invoice_number' => fake()->unique()->regexify('LAB-[0-9]{10}'),
            'subtotal' => $subtotal,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
            'total' => round($subtotal - $discountAmount, 2),
            'status' => 'pending',
            'created_by' => $user->id,
            'shift_id' => Shift::factory()->state(['user_id' => $user->id]),
        ];
    }

    /**
     * Mark the lab invoice as paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
        ]);
    }

    /**
     * Mark the lab invoice as pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }
}
