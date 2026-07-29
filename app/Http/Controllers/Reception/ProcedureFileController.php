<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Models\Procedure;
use App\Services\ProcedureFileBuilder;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ProcedureFileController extends Controller
{
    /**
     * Stream the combined printable procedure file.
     */
    public function __invoke(Procedure $procedure, ProcedureFileBuilder $builder): Response
    {
        $procedure->load(['patient', 'procedureType.documents']);

        try {
            $pdf = $builder->build($procedure);
        } catch (RuntimeException) {
            abort(404);
        }

        $mrn = $procedure->patient?->mrn ?? 'procedure';
        $filename = $mrn.'-procedure-file.pdf';

        return response($pdf, SymfonyResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
