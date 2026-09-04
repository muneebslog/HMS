<?php

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Models\Expense;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'user_id' => User::factory(),
            'name' => fake()->word(),
            'amount' => fake()->randomFloat(2, 0, 1000),
            'approval_status' => ApprovalStatus::Pending,
        ];
    }

    /**
     * Mark the expense as approved.
     */
    public function approved(?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'approval_status' => ApprovalStatus::Approved,
            'reviewed_by' => $reviewer?->id ?? User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Mark the expense as rejected.
     */
    public function rejected(?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'approval_status' => ApprovalStatus::Rejected,
            'reviewed_by' => $reviewer?->id ?? User::factory(),
            'reviewed_at' => now(),
        ]);
    }
}
