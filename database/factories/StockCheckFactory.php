<?php

namespace Database\Factories;

use App\Models\HealthAide;
use App\Models\Place;
use App\Models\StockCheck;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockCheck> */
class StockCheckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'place_id' => Place::factory(),
            'health_aide_id' => HealthAide::factory(),
            'user_id' => User::factory(),
            'checked_at' => now(),
        ];
    }
}
