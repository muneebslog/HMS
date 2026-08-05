<?php

namespace Database\Factories;

use App\Enums\PaymentMode;
use App\Models\Procedure;
use App\Models\ProcedurePayment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedurePayment>
 */
class ProcedurePaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'procedure_id' => Procedure::factory(),
            'amount' => $this->faker->randomFloat(2, 50, 1000),
            'mode' => PaymentMode::Cash->value,
            'created_by' => User::factory(),
            'shift_id' => Shift::factory(),
        ];
    }

    /**
     * Indicate that the payment is not attributed to any shift.
     */
    public function withoutShift(): static
    {
        return $this->state(fn (array $attributes): array => [
            'shift_id' => null,
        ]);
    }
}
