<?php

namespace Database\Factories;

use App\Enums\FetalPresentation;
use App\Enums\LiquorVolume;
use App\Enums\PlacentaPosition;
use App\Models\GyneUltrasound;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GyneUltrasound>
 */
class GyneUltrasoundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'queue_token_id' => QueueToken::factory(),
            'patient_id' => Patient::factory(),
            'scanned_on' => today()->toDateString(),
            'fetus_count' => 1,
            'ga_weeks' => fake()->numberBetween(8, 38),
            'ga_days' => fake()->numberBetween(0, 6),
            'fetal_heart_rate' => fake()->numberBetween(120, 155),
            'presentation' => FetalPresentation::Cephalic,
            'placenta' => fake()->randomElement([PlacentaPosition::Anterior, PlacentaPosition::Posterior, PlacentaPosition::Fundal]),
            'liquor' => LiquorVolume::Adequate,
            'afi' => fake()->randomFloat(1, 8, 18),
            'efw_grams' => fake()->numberBetween(500, 3500),
            'impression' => null,
            'recorded_by' => User::factory(),
            'updated_by' => null,
        ];
    }
}
