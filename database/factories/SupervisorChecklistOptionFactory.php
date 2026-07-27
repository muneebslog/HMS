<?php

namespace Database\Factories;

use App\Models\SupervisorChecklistOption;
use App\Models\SupervisorChecklistQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupervisorChecklistOption>
 */
class SupervisorChecklistOptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SupervisorChecklistOption>
     */
    protected $model = SupervisorChecklistOption::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_id' => SupervisorChecklistQuestion::factory(),
            'option_text' => fake()->word(),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the option is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
