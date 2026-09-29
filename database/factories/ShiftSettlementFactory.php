<?php

namespace Database\Factories;

use App\Enums\FinanceShiftPeriod;
use App\Models\Shift;
use App\Models\ShiftSettlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftSettlement>
 */
class ShiftSettlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $expected = fake()->randomFloat(2, 1000, 50000);
        $received = $expected - fake()->randomFloat(2, 0, 200);

        return [
            'shift_id' => Shift::factory(),
            'settled_by' => User::factory(),
            'business_date' => now()->toDateString(),
            'period' => FinanceShiftPeriod::Morning,
            'opening_balance' => 5000,
            'cash_sales' => $expected - 5000,
            'online_sales' => 0,
            'doctor_payouts' => 0,
            'expenses' => 0,
            'declared_closing_balance' => $received,
            'expected_amount' => $expected,
            'received_amount' => $received,
            'difference' => $received - $expected,
            'settled_at' => now(),
        ];
    }
}
