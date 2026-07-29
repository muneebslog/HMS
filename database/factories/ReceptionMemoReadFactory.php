<?php

namespace Database\Factories;

use App\Models\ReceptionMemo;
use App\Models\ReceptionMemoRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceptionMemoRead>
 */
class ReceptionMemoReadFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ReceptionMemoRead>
     */
    protected $model = ReceptionMemoRead::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reception_memo_id' => ReceptionMemo::factory(),
            'user_id' => User::factory(),
            'read_at' => now(),
        ];
    }
}
