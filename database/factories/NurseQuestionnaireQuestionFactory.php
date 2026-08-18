<?php

namespace Database\Factories;

use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NurseQuestionnaireQuestion>
 */
class NurseQuestionnaireQuestionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<NurseQuestionnaireQuestion>
     */
    protected $model = NurseQuestionnaireQuestion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'questionnaire_id' => NurseQuestionnaire::factory(),
            'question_text' => fake()->sentence(),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the question is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
