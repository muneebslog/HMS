<?php

namespace App\Http\Controllers\Indoor;

use App\Enums\ProcedureDocumentKind;
use App\Http\Controllers\Controller;
use App\Models\Procedure;
use App\Models\ProcedureDocument;
use Illuminate\View\View;

class ProcedureDischargeCertificateController extends Controller
{
    /**
     * Display the printable discharge certificate and track its generation.
     */
    public function __invoke(Procedure $procedure): View
    {
        $procedure->load([
            'patient',
            'doctor',
            'procedureType',
            'room',
            'dischargeDetail',
            'operationNote',
            'deliveryNote',
        ]);

        $document = ProcedureDocument::query()->firstOrCreate(
            [
                'procedure_id' => $procedure->id,
                'kind' => ProcedureDocumentKind::DischargeCertificate,
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

        return view('procedures.discharge-certificate', [
            'procedure' => $procedure,
            'dischargeDetail' => $procedure->dischargeDetail,
        ]);
    }
}
