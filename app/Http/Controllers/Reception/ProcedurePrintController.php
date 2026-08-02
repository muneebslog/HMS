<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Models\Procedure;
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
            'payments' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'payments.creator',
            'payments.shift',
        ]);

        return view('procedures.print', [
            'procedure' => $procedure,
            'totalPaid' => $procedure->totalPaid(),
            'balance' => $procedure->balance(),
        ]);
    }
}
