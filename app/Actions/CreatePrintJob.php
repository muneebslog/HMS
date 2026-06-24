<?php

namespace App\Actions;

use App\Enums\PrintJobStatus;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\PrintJob;

class CreatePrintJob
{
    /**
     * Create a pending print job for the given invoice.
     */
    public function create(Invoice|LabInvoice $invoice): PrintJob
    {
        $data = [
            'status' => PrintJobStatus::Pending,
            'payload' => [
                'type' => $invoice instanceof LabInvoice ? 'lab_invoice' : 'invoice',
                'source' => 'web',
            ],
            'attempts' => 0,
        ];

        if ($invoice instanceof LabInvoice) {
            $data['lab_invoice_id'] = $invoice->id;
        } else {
            $data['invoice_id'] = $invoice->id;
        }

        return PrintJob::create($data);
    }
}
