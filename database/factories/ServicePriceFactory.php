<?php

namespace Database\Factories;

use App\Enums\TokenDisplayLayout;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\ServicePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServicePrice>
 */
class ServicePriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'doctor_id' => fake()->optional(0.8)->passthrough(Doctor::factory()->create()->id),
            'price' => fake()->randomFloat(2, 10, 1000),
            'doctor_share' => fake()->optional()->randomFloat(2, 0, 100),
            'token_starts_from' => 1,
            'is_file_check' => false,
            'display_layout' => TokenDisplayLayout::Board,
        ];
    }

    /**
     * Indicate that tokens for this price appear in the file-check TV panel.
     */
    public function fileCheck(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_file_check' => true,
        ]);
    }

    /**
     * Indicate that this price uses the centered single-token TV layout.
     */
    public function singleTokenDisplay(): static
    {
        return $this->state(fn (array $attributes) => [
            'display_layout' => TokenDisplayLayout::SingleToken,
        ]);
    }
}
