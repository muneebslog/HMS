<?php

namespace Database\Factories;

use App\Enums\TokenResetType;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'is_standalone' => fake()->boolean(),
            'needs_vitals' => false,
            'ends_at_vitals' => false,
            'needs_medication' => false,
            'is_drip' => false,
            'appear_on_er' => false,
            'token_reset_type' => fake()->randomElement(array_column(TokenResetType::cases(), 'value')),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the service is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the service requires vitals before the doctor.
     */
    public function needsVitals(): static
    {
        return $this->state(fn (array $attributes) => [
            'needs_vitals' => true,
        ]);
    }

    /**
     * Indicate that the service finishes after vitals are recorded.
     */
    public function endsAtVitals(): static
    {
        return $this->state(fn (array $attributes) => [
            'needs_vitals' => true,
            'ends_at_vitals' => true,
        ]);
    }

    /**
     * Indicate that the service requires a doctor medication page.
     */
    public function needsMedication(): static
    {
        return $this->state(fn (array $attributes) => [
            'needs_medication' => true,
        ]);
    }

    /**
     * Indicate that the service is a billable drip service.
     */
    public function drip(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_drip' => true,
        ]);
    }

    /**
     * Indicate that the service appears on the ER station page.
     */
    public function appearOnEr(): static
    {
        return $this->state(fn (array $attributes) => [
            'appear_on_er' => true,
        ]);
    }
}
