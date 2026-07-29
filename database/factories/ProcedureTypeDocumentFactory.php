<?php

namespace Database\Factories;

use App\Models\ProcedureType;
use App\Models\ProcedureTypeDocument;
use FPDF;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<ProcedureTypeDocument>
 */
class ProcedureTypeDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).'.pdf';

        return [
            'procedure_type_id' => ProcedureType::factory(),
            'path' => 'procedure-types/tmp/'.$name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'sort_order' => 0,
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ProcedureTypeDocument $document): void {
            if (Storage::disk('local')->exists($document->path)) {
                return;
            }

            if ($document->isPdf()) {
                $pdf = new FPDF;
                $pdf->AddPage();
                $pdf->SetFont('Helvetica', '', 12);
                $pdf->Cell(40, 10, 'Procedure document');
                Storage::disk('local')->put($document->path, $pdf->Output('S'));

                return;
            }

            if ($document->isImage()) {
                $image = imagecreatetruecolor(200, 300);
                $background = imagecolorallocate($image, 240, 240, 240);
                imagefill($image, 0, 0, $background);

                ob_start();
                imagepng($image);
                $contents = ob_get_clean();
                imagedestroy($image);

                Storage::disk('local')->put($document->path, $contents ?: '');
            }
        });
    }

    /**
     * Indicate that the document is a PNG image.
     */
    public function image(): static
    {
        return $this->state(function (): array {
            $name = fake()->unique()->words(2, true).'.png';

            return [
                'path' => 'procedure-types/tmp/'.$name,
                'original_name' => $name,
                'mime_type' => 'image/png',
            ];
        });
    }
}
