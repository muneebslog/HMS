<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Models\Procedure;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProcedureApparentInvoicePrintController extends Controller
{
    /**
     * Display the printable A4 apparent (company) payment receipt.
     */
    public function __invoke(Procedure $procedure): View
    {
        $procedure->load([
            'patient',
            'doctor',
            'procedureType',
            'apparentInvoice.items',
        ]);

        $invoice = $procedure->apparentInvoice;

        if ($invoice === null) {
            throw new NotFoundHttpException(__('No apparent invoice has been saved for this procedure.'));
        }

        return view('procedures.apparent-print', [
            'procedure' => $procedure,
            'invoice' => $invoice,
        ]);
    }
}
