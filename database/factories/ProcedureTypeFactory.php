<?php

namespace Database\Factories;

use App\Enums\ProcedureNoteStyle;
use App\Models\ProcedureType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureType>
 */
class ProcedureTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'is_active' => true,
            'requires_birth_certificate' => false,
            'requires_fetal_heart' => false,
            'note_style' => ProcedureNoteStyle::Operation,
        ];
    }

    /**
     * Indicate that the procedure type is inactive.
     */
    public function inactive(): self
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    /**
     * SVD-style delivery type.
     */
    public function delivery(): self
    {
        return $this->state(fn () => [
            'requires_birth_certificate' => true,
            'requires_fetal_heart' => true,
            'note_style' => ProcedureNoteStyle::Delivery,
        ]);
    }

    /**
     * LSCS-style surgical delivery type.
     */
    public function lscs(): self
    {
        return $this->state(fn () => [
            'requires_birth_certificate' => true,
            'requires_fetal_heart' => true,
            'note_style' => ProcedureNoteStyle::Operation,
        ]);
    }
}
