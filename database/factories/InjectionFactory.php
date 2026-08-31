<?php

namespace Database\Factories;

use App\Enums\InjectionAdministrationType;
use App\Enums\StockLocation;
use App\Models\Injection;
use App\Services\InventoryStockService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Injection>
 */
class InjectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Injection',
            'short_form' => null,
            'default_administration_type' => fake()->randomElement(InjectionAdministrationType::cases()),
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Injection $injection): void {
            app(InventoryStockService::class)->adjust($injection, StockLocation::BackStorage, 100);
        });
    }

    public function withFrontStock(int $quantity): static
    {
        return $this->afterCreating(function (Injection $injection) use ($quantity): void {
            app(InventoryStockService::class)->adjust($injection, StockLocation::FrontWorking, $quantity);
        });
    }

    /**
     * Indicate that the injection is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
