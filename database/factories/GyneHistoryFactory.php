<?php

namespace Database\Factories;

use App\Enums\GyneOperation;
use App\Enums\GyneProblem;
use App\Models\GyneHistory;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GyneHistory>
 */
class GyneHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gravida = fake()->numberBetween(1, 6);
        $miscarriages = fake()->numberBetween(0, $gravida - 1);
        $para = $gravida - $miscarriages - 1;

        return [
            'queue_token_id' => QueueToken::factory(),
            'patient_id' => Patient::factory(),
            'gravida' => $gravida,
            'para' => $para,
            'living_children' => $para,
            'miscarriages' => $miscarriages,
            'married_since' => fake()->dateTimeBetween('-12 years', '-1 year')->format('Y-m-01'),
            'last_pregnancy_at' => fake()->dateTimeBetween('-5 years', '-1 year')->format('Y-m-01'),
            'lmp' => now()->subWeeks(fake()->numberBetween(4, 36))->toDateString(),
            'problems' => fake()->randomElements(GyneProblem::values(), fake()->numberBetween(0, 2)),
            'problems_other' => null,
            'operations' => fake()->randomElements(GyneOperation::values(), fake()->numberBetween(0, 1)),
            'operations_other' => null,
            'recorded_by' => User::factory(),
            'updated_by' => null,
        ];
    }
}
