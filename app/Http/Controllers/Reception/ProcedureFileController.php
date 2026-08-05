<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Models\Procedure;
use App\Services\ProcedureFileBuilder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ProcedureFileController extends Controller
{
    /**
     * Stream the combined printable procedure file and mark it printed.
     */
    public function __invoke(Procedure $procedure, ProcedureFileBuilder $builder): Response
    {
        $procedure->load(['patient', 'procedureType.documents']);

        try {
            $pdf = $builder->build($procedure);
        } catch (RuntimeException $exception) {
            Log::warning('Unable to build the combined procedure file.', [
                'procedure_id' => $procedure->id,
                'procedure_type_id' => $procedure->procedure_type_id,
                'documents' => $procedure->procedureType?->documents->count() ?? 0,
                'reason' => $exception->getMessage(),
            ]);

            abort(404);
        }

        if ($procedure->file_printed_at === null) {
            $procedure->update([
                'file_printed_at' => now(),
                'file_printed_by' => auth()->id(),
            ]);
        }

        $mrn = $procedure->patient?->mrn ?? 'procedure';
        $filename = $mrn.'-procedure-file.pdf';

        return response($pdf, SymfonyResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
