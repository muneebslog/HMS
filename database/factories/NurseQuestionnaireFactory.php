<?php

namespace Database\Factories;

use App\Models\NurseQuestionnaire;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NurseQuestionnaire>
 */
class NurseQuestionnaireFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<NurseQuestionnaire>
     */
    protected $model = NurseQuestionnaire::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory()->admin(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'interval_hours' => 2,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the questionnaire is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
