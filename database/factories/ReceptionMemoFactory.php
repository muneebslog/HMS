<?php

namespace Database\Factories;

use App\Models\ReceptionMemo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceptionMemo>
 */
class ReceptionMemoFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ReceptionMemo>
     */
    protected $model = ReceptionMemo::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->paragraph(),
            'color' => 'amber',
        ];
    }
}
