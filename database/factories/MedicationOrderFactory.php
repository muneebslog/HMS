<?php

namespace Database\Factories;

use App\Enums\MedicationOrderStatus;
use App\Models\Doctor;
use App\Models\MedicationOrder;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicationOrder>
 */
class MedicationOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'queue_token_id' => QueueToken::factory(),
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory(),
            'prescribed_by' => User::factory()->doctor(),
            'status' => MedicationOrderStatus::Pending,
            'notes' => null,
            'administered_by' => null,
            'administered_at' => null,
        ];
    }

    /**
     * Indicate that the order has no assigned doctor (standalone service).
     */
    public function withoutDoctor(): static
    {
        return $this->state(fn (array $attributes) => [
            'doctor_id' => null,
        ]);
    }

    /**
     * Indicate that the order has been administered.
     */
    public function administered(?User $by = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MedicationOrderStatus::Administered,
            'administered_by' => $by?->id ?? User::factory()->receptionist(),
            'administered_at' => now(),
        ]);
    }
}
