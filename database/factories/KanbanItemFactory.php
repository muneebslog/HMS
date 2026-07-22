<?php

namespace Database\Factories;

use App\Enums\KanbanStatus;
use App\Models\KanbanItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KanbanItem>
 */
class KanbanItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<KanbanItem>
     */
    protected $model = KanbanItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'status' => $this->faker->randomElement(KanbanStatus::values()),
            'position' => $this->faker->numberBetween(0, 100),
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate the item belongs to the given status.
     */
    public function status(KanbanStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
