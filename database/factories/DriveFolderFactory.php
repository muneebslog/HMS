<?php

namespace Database\Factories;

use App\Models\DriveFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriveFolder>
 */
class DriveFolderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => fake()->unique()->words(2, true),
            'created_by' => User::factory()->admin(),
        ];
    }

    /**
     * Place the folder inside the given parent folder.
     */
    public function inFolder(DriveFolder $folder): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $folder->id,
        ]);
    }
}
