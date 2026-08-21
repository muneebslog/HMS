<?php

namespace Database\Factories;

use App\Models\StockCheck;
use App\Models\StockCheckItem;
use App\Models\Thing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockCheckItem> */
class StockCheckItemFactory extends Factory
{
    public function definition(): array
    {
        $stockPoint = fake()->numberBetween(5, 50);
        $counted = fake()->numberBetween(0, $stockPoint);

        return [
            'stock_check_id' => StockCheck::factory(),
            'thing_id' => Thing::factory(),
            'stock_point' => $stockPoint,
            'counted_quantity' => $counted,
            'refill_needed' => max(0, $stockPoint - $counted),
        ];
    }
}
