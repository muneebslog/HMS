<?php

namespace Database\Factories;

use App\Enums\ProcedureAttachmentType;
use App\Models\Procedure;
use App\Models\ProcedureAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureAttachment>
 */
class ProcedureAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true).'.pdf';

        return [
            'procedure_id' => Procedure::factory(),
            'type' => ProcedureAttachmentType::Consent,
            'path' => 'procedure-attachments/tmp/'.$name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'uploaded_by' => User::factory(),
        ];
    }
}
