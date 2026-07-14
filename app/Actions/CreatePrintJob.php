<?php

namespace App\Actions;

use App\Enums\PrintJobStatus;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\PrintJob;
use App\Models\Shift;

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

    /**
     * Create pending print jobs for a lab invoice receipt.
     *
     * @return array<int, PrintJob>
     */
    public function createLabInvoiceReceipts(LabInvoice $invoice, string $qrUrl): array
    {
        return [
            $this->createLabCopy($invoice, $qrUrl, 'patient'),
            $this->createLabCopy($invoice, $qrUrl, 'lab'),
        ];
    }

    /**
     * Create a pending print job for the given shift closing report.
     */
    public function createForShift(Shift $shift): PrintJob
    {
        return PrintJob::create([
            'shift_id' => $shift->id,
            'status' => PrintJobStatus::Pending,
            'payload' => [
                'type' => 'shift_report',
                'source' => 'web',
            ],
            'attempts' => 0,
        ]);
    }

    /**
     * Create a single lab invoice copy print job.
     */
    private function createLabCopy(LabInvoice $invoice, string $qrUrl, string $copyFor): PrintJob
    {
        return PrintJob::create([
            'lab_invoice_id' => $invoice->id,
            'status' => PrintJobStatus::Pending,
            'payload' => [
                'type' => 'lab_invoice',
                'source' => 'web',
                'copy_for' => $copyFor,
                'qr_url' => $qrUrl,
            ],
            'attempts' => 0,
        ]);
    }
}
