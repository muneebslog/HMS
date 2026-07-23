<?php

namespace Database\Factories;

use App\Models\KanbanItem;
use App\Models\KanbanItemComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KanbanItemComment>
 */
class KanbanItemCommentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<KanbanItemComment>
     */
    protected $model = KanbanItemComment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kanban_item_id' => KanbanItem::factory(),
            'user_id' => User::factory(),
            'content' => $this->faker->paragraph(),
        ];
    }
}
