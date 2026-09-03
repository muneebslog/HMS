<?php

namespace App\Http\Controllers\Reception;

use App\Enums\ProcedureDocumentKind;
use App\Http\Controllers\Controller;
use App\Models\Procedure;
use App\Models\ProcedureDocument;
use Illuminate\View\View;

class ProcedurePrintController extends Controller
{
    /**
     * Display the printable A4 procedure bill with the latest payment data.
     */
    public function __invoke(Procedure $procedure): View
    {
        $procedure->load([
            'patient',
            'doctor',
            'procedureType',
            'room',
            'creator',
            'payments' => fn ($query) => $query->active()->orderBy('created_at')->orderBy('id'),
            'payments.creator',
            'payments.shift',
        ]);

        $document = ProcedureDocument::query()->firstOrCreate(
            [
                'procedure_id' => $procedure->id,
                'kind' => ProcedureDocumentKind::Bill,
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

        return view('procedures.print', [
            'procedure' => $procedure,
            'totalPaid' => $procedure->totalPaid(),
            'balance' => $procedure->balance(),
        ]);
    }
}
