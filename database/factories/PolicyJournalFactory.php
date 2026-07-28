<?php

namespace Database\Factories;

use App\Enums\PolicyJournalStatus;
use App\Models\PolicyJournal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicyJournal>
 */
class PolicyJournalFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<PolicyJournal>
     */
    protected $model = PolicyJournal::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'title' => $this->faker->sentence(),
            'incident' => $this->faker->paragraphs(2, true),
            'resolution' => $this->faker->paragraphs(2, true),
            'policy' => $this->faker->paragraphs(3, true),
            'category' => $this->faker->randomElement([
                'Reception',
                'Billing',
                'Clinical',
                'IT',
                'HR',
                'Operations',
            ]),
            'tags' => $this->faker->words(3),
            'effective_date' => $this->faker->optional()->date(),
            'review_date' => $this->faker->optional()->date(),
            'status' => $this->faker->randomElement(PolicyJournalStatus::values()),
            'attachments' => null,
        ];
    }

    /**
     * Indicate the entry belongs to the given status.
     */
    public function status(PolicyJournalStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    /**
     * Indicate the entry belongs to the given category.
     */
    public function category(string $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => $category,
        ]);
    }
}
