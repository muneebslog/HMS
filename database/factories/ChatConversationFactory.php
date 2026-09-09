<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_one_id' => User::factory(),
            'user_two_id' => User::factory(),
            'last_message_at' => null,
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (ChatConversation $conversation): void {
            if ($conversation->user_one_id > $conversation->user_two_id) {
                [$conversation->user_one_id, $conversation->user_two_id] = [
                    $conversation->user_two_id,
                    $conversation->user_one_id,
                ];
            }
        })->afterCreating(function (ChatConversation $conversation): void {
            if ($conversation->user_one_id > $conversation->user_two_id) {
                $conversation->forceFill([
                    'user_one_id' => $conversation->user_two_id,
                    'user_two_id' => $conversation->user_one_id,
                ])->saveQuietly();
            }
        });
    }

    /**
     * Set both conversation participants.
     */
    public function between(User $first, User $second): static
    {
        [$userOneId, $userTwoId] = $first->id < $second->id
            ? [$first->id, $second->id]
            : [$second->id, $first->id];

        return $this->state(fn (array $attributes) => [
            'user_one_id' => $userOneId,
            'user_two_id' => $userTwoId,
        ]);
    }
}
