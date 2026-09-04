<?php

namespace Database\Factories;

use App\Enums\BirthMultiplicity;
use App\Enums\LivingStatus;
use App\Models\Procedure;
use App\Models\ProcedureBirthCertificateDetail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureBirthCertificateDetail>
 */
class ProcedureBirthCertificateDetailFactory extends Factory
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
            'father_name' => fake()->name('male'),
            'mother_name' => fake()->name('female'),
            'grandfather_name' => fake()->name('male'),
            'maternal_grandfather_name' => fake()->name('male'),
            'father_age' => fake()->numberBetween(20, 55),
            'mother_age' => fake()->numberBetween(18, 45),
            'father_cnic' => fake()->numerify('#####-#######-#'),
            'mother_cnic' => fake()->numerify('#####-#######-#'),
            'home_address' => fake()->address(),
            'born_at' => now(),
            'sex' => fake()->randomElement(['male', 'female']),
            'status' => LivingStatus::Living,
            'baby_name' => fake()->optional()->firstName(),
            'multiplicity' => BirthMultiplicity::Single,
            'child_order' => null,
            'recorded_by' => User::factory(),
        ];
    }

    /**
     * Indicate the birth was a twin.
     */
    public function twin(int $childOrder = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'multiplicity' => BirthMultiplicity::Twin,
            'child_order' => $childOrder,
        ]);
    }
}
