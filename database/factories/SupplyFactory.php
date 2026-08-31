<?php

namespace Database\Factories;

use App\Enums\StockLocation;
use App\Models\Supply;
use App\Services\InventoryStockService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supply>
 */
class SupplyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'short_form' => null,
            'category' => fake()->randomElement(['consumables', 'iv_access', 'fluids', 'airway']),
            'unit' => fake()->randomElement(['piece', 'box', 'pack', null]),
            'default_par' => fake()->optional()->numberBetween(1, 20),
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Supply $supply): void {
            app(InventoryStockService::class)->adjust($supply, StockLocation::BackStorage, 100);
        });
    }

    public function withFrontStock(int $quantity): static
    {
        return $this->afterCreating(function (Supply $supply) use ($quantity): void {
            app(InventoryStockService::class)->adjust($supply, StockLocation::FrontWorking, $quantity);
        });
    }

    /**
     * Indicate that the supply is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
