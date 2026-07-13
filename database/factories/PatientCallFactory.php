<?php

namespace Database\Factories;

use App\Models\PatientCall;
use App\Models\QueueToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientCall>
 */
class PatientCallFactory extends Factory
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
            'called_by' => User::factory(),
            'called_at' => now(),
            'notes' => null,
        ];
    }
}
