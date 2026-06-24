<?php

namespace Database\Factories;

use App\Enums\RoleRequestStatus;
use App\Enums\UserRole;
use App\Models\RoleRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleRequest>
 */
class RoleRequestFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = RoleRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'requested_role' => fake()->randomElement([UserRole::Receptionist, UserRole::Management]),
            'status' => RoleRequestStatus::Pending,
            'message' => fake()->optional()->sentence(),
            'admin_notes' => null,
            'processed_by' => null,
            'processed_at' => null,
        ];
    }

    /**
     * Indicate that the request is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoleRequestStatus::Pending,
            'processed_by' => null,
            'processed_at' => null,
        ]);
    }

    /**
     * Indicate that the request has been approved.
     */
    public function approved(?User $processor = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoleRequestStatus::Approved,
            'processed_by' => $processor !== null ? $processor->id : User::factory(),
            'processed_at' => now(),
        ]);
    }

    /**
     * Indicate that the request has been rejected.
     */
    public function rejected(?User $processor = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoleRequestStatus::Rejected,
            'processed_by' => $processor !== null ? $processor->id : User::factory(),
            'processed_at' => now(),
        ]);
    }
}
