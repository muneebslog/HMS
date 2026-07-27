<?php

namespace Database\Factories;

use App\Models\SupervisorChecklistQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupervisorChecklistQuestion>
 */
class SupervisorChecklistQuestionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SupervisorChecklistQuestion>
     */
    protected $model = SupervisorChecklistQuestion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
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
