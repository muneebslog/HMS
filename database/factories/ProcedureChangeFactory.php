<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\ProcedureChange;
use App\Models\ProcedureType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureChange>
 */
class ProcedureChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fromType = ProcedureType::factory();
        $toType = ProcedureType::factory();
        $packagePrice = $this->faker->randomFloat(2, 5000, 20000);
        $discountAmount = $this->faker->randomFloat(2, 0, 1000);
        $toAmount = max(0, $packagePrice - $discountAmount);

        return [
            'procedure_id' => Procedure::factory(),
            'from_procedure_type_id' => $fromType,
            'to_procedure_type_id' => $toType,
            'from_name' => fn (array $attributes) => ProcedureType::find($attributes['from_procedure_type_id'])?->name
                ?? $this->faker->words(2, true),
            'to_name' => fn (array $attributes) => ProcedureType::find($attributes['to_procedure_type_id'])?->name
                ?? $this->faker->words(2, true),
            'from_amount' => $this->faker->randomFloat(2, 1000, 10000),
            'to_amount' => $toAmount,
            'package_price' => $packagePrice,
            'discount_amount' => $discountAmount,
            'changed_by' => User::factory(),
        ];
    }
}
