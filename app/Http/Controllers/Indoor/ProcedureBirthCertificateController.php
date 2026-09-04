<?php

namespace App\Http\Controllers\Indoor;

use App\Enums\ProcedureDocumentKind;
use App\Http\Controllers\Controller;
use App\Models\Procedure;
use App\Models\ProcedureDocument;
use Illuminate\View\View;

class ProcedureBirthCertificateController extends Controller
{
    /**
     * Display the printable birth certificate and track its generation.
     */
    public function __invoke(Procedure $procedure): View
    {
        $procedure->load([
            'patient',
            'doctor',
            'procedureType',
            'dischargeDetail',
            'deliveryNote',
            'birthCertificateDetail',
        ]);

        $hasCertificateDetails = $procedure->birthCertificateDetail !== null;

        if (! $procedure->procedureType?->requires_birth_certificate && ! $hasCertificateDetails) {
            abort(404);
        }

        $document = ProcedureDocument::query()->firstOrCreate(
            [
                'procedure_id' => $procedure->id,
                'kind' => ProcedureDocumentKind::BirthCertificate,
            ],
            [
                'generated_at' => now(),
                'generated_by' => auth()->id(),
            ]
        );

        $updates = [];

        if ($document->generated_at === null) {
            $updates['generated_at'] = now();
            $updates['generated_by'] = auth()->id();
        }

        if ($document->printed_at === null) {
            $updates['printed_at'] = now();
            $updates['printed_by'] = auth()->id();
        }

        if ($updates !== []) {
            $document->update($updates);
        }

        return view('procedures.birth-certificate', [
            'procedure' => $procedure,
            'certificate' => $procedure->birthCertificateDetail,
            'dischargeDetail' => $procedure->dischargeDetail,
            'deliveryNote' => $procedure->deliveryNote,
        ]);
    }
}
