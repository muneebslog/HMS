<?php

namespace App\Services;

use App\Models\Procedure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;

class ProcedureFileBuilder
{
    /**
     * Build a combined printable PDF for the given procedure.
     *
     * @throws RuntimeException
     */
    public function build(Procedure $procedure): string
    {
        $documents = $procedure->procedureType?->documents
            ?? Collection::make();

        if ($documents->isEmpty()) {
            throw new RuntimeException('No documents linked to this procedure type.');
        }

        $pdf = new Fpdi;

        foreach ($documents as $document) {
            $absolutePath = Storage::disk('local')->path($document->path);

            if (! is_file($absolutePath)) {
                throw new RuntimeException("Missing document file: {$document->original_name}");
            }

            if ($document->isPdf()) {
                $this->appendPdf($pdf, $absolutePath);
            } elseif ($document->isImage()) {
                $this->appendImage($pdf, $absolutePath);
            } else {
                throw new RuntimeException("Unsupported document type: {$document->original_name}");
            }
        }

        return $pdf->Output('S');
    }

    /**
     * Append every page from a source PDF.
     */
    private function appendPdf(Fpdi $pdf, string $absolutePath): void
    {
        $pageCount = $pdf->setSourceFile($absolutePath);

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $templateId = $pdf->importPage($pageNumber);
            $size = $pdf->getTemplateSize($templateId);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);
        }
    }

    /**
     * Append an image as a single A4 page.
     */
    private function appendImage(Fpdi $pdf, string $absolutePath): void
    {
        $imageSize = getimagesize($absolutePath);

        if ($imageSize === false) {
            throw new RuntimeException('Unable to read image dimensions.');
        }

        [$imageWidth, $imageHeight] = $imageSize;

        $orientation = $imageWidth >= $imageHeight ? 'L' : 'P';
        $pdf->AddPage($orientation, 'A4');

        $pageWidth = $pdf->GetPageWidth();
        $pageHeight = $pdf->GetPageHeight();
        $margin = 10;
        $maxWidth = $pageWidth - ($margin * 2);
        $maxHeight = $pageHeight - ($margin * 2);

        $scale = min($maxWidth / $imageWidth, $maxHeight / $imageHeight);
        $drawWidth = $imageWidth * $scale;
        $drawHeight = $imageHeight * $scale;
        $x = ($pageWidth - $drawWidth) / 2;
        $y = ($pageHeight - $drawHeight) / 2;

        $pdf->Image($absolutePath, $x, $y, $drawWidth, $drawHeight);
    }
}
