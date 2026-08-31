<?php

namespace Database\Factories;

use App\Models\DutyLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DutyLocation>
 */
class DutyLocationFactory extends Factory
{
    protected $model = DutyLocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
