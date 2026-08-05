<?php

namespace Database\Factories;

use App\Enums\ProcedureDocumentKind;
use App\Models\Procedure;
use App\Models\ProcedureDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureDocument>
 */
class ProcedureDocumentFactory extends Factory
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
            'kind' => ProcedureDocumentKind::DischargeCertificate,
            'generated_at' => null,
            'generated_by' => null,
            'printed_at' => null,
            'printed_by' => null,
            'path' => null,
        ];
    }
}
