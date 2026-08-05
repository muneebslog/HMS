<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\ProcedureDeliveryNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureDeliveryNote>
 */
class ProcedureDeliveryNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'procedure_id' => Procedure::factory(),
            'labour_type' => $this->faker->randomElement(['Spontaneous', 'Induced']),
            'procedure_name' => 'Normal Delivery',
            'obstetrician' => $this->faker->name(),
            'assistant' => $this->faker->name(),
            'delivered_at' => now(),
            'analgesia' => null,
            'delivery_details' => $this->faker->sentence(),
            'labour_first_stage' => null,
            'labour_second_stage' => null,
            'labour_third_stage' => null,
            'complications' => null,
            'baby_sex' => $this->faker->randomElement(['Male', 'Female']),
            'baby_weight' => $this->faker->numberBetween(2, 4).'.'.$this->faker->numberBetween(0, 9).' kg',
            'apgar_score' => $this->faker->numberBetween(7, 10).'/10',
            'resuscitated_by' => null,
            'recorded_by' => null,
        ];
    }
}
