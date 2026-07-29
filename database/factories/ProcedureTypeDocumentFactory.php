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

            $contents = match ($document->extension()) {
                'pdf' => $this->fakePdfContents(),
                'png' => $this->fakeImageContents('png'),
                'jpg', 'jpeg' => $this->fakeImageContents('jpeg'),
                default => null,
            };

            if ($contents !== null) {
                Storage::disk('local')->put($document->path, $contents);
            }
        });
    }

    /**
     * Indicate that the document is a PNG image.
     */
    public function image(): static
    {
        return $this->imageState('png', 'image/png');
    }

    /**
     * Indicate that the document is a JPEG image.
     */
    public function jpeg(): static
    {
        return $this->imageState('jpg', 'image/jpeg');
    }

    /**
     * Build an image state for the given extension and mime type.
     */
    private function imageState(string $extension, string $mimeType): static
    {
        return $this->state(function () use ($extension, $mimeType): array {
            $name = fake()->unique()->words(2, true).'.'.$extension;

            return [
                'path' => 'procedure-types/tmp/'.$name,
                'original_name' => $name,
                'mime_type' => $mimeType,
            ];
        });
    }

    /**
     * Generate a single page PDF document.
     */
    private function fakePdfContents(): string
    {
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(40, 10, 'Procedure document');

        return $pdf->Output('S');
    }

    /**
     * Generate image contents in the given format.
     */
    private function fakeImageContents(string $format): string
    {
        $image = imagecreatetruecolor(200, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 240));

        ob_start();

        if ($format === 'jpeg') {
            imagejpeg($image);
        } else {
            imagepng($image);
        }

        $contents = ob_get_clean();
        imagedestroy($image);

        return $contents ?: '';
    }
}
