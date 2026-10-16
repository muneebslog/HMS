<?php

namespace Database\Factories;

use App\Models\ShiftTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftTemplate>
 */
class ShiftTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'starts_at' => '08:00:00',
            'ends_at' => '15:00:00',
            'color' => 'sky',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * A shift with the given timings, e.g. timed('22:00', '08:00').
     */
    public function timed(string $startsAt, string $endsAt): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $startsAt.':00',
            'ends_at' => $endsAt.':00',
        ]);
    }
}
