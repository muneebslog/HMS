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
     * Create a pending print job for the given invoice. A lab invoice always prints
     * both slips (patient copy with payment + QR, lab copy with tests + samples), so
     * reprints match the original; the patient copy's job is returned.
     */
    public function create(Invoice|LabInvoice $invoice): PrintJob
    {
        if ($invoice instanceof LabInvoice) {
            return $this->createLabInvoiceReceipts($invoice)[0];
        }

        return PrintJob::create([
            'invoice_id' => $invoice->id,
            'status' => PrintJobStatus::Pending,
            'payload' => [
                'type' => 'invoice',
                'source' => 'web',
            ],
            'attempts' => 0,
        ]);
    }

    /**
     * Create pending print jobs for a lab invoice receipt: [patient copy, lab copy, ...].
     * A cross match takes two samples, and each sample needs its own lab slip attached,
     * so an extra lab copy is printed. The QR links to the patient's public results page
     * unless a URL is given.
     *
     * @return non-empty-list<PrintJob>
     */
    public function createLabInvoiceReceipts(LabInvoice $invoice, ?string $qrUrl = null): array
    {
        $qrUrl ??= (string) $invoice->publicReportsUrl();

        $jobs = [
            $this->createLabCopy($invoice, $qrUrl, 'patient'),
            $this->createLabCopy($invoice, $qrUrl, 'lab'),
        ];

        if ($this->hasCrossMatch($invoice)) {
            $jobs[] = $this->createLabCopy($invoice, $qrUrl, 'lab');
        }

        return $jobs;
    }

    /**
     * Create a pending print job for a no-charge retake slip listing only the given tests,
     * printed when a patient comes back to give a sample again.
     *
     * @param  list<int>  $itemIds
     */
    public function createLabSampleRetakeSlip(LabInvoice $invoice, array $itemIds): PrintJob
    {
        return PrintJob::create([
            'lab_invoice_id' => $invoice->id,
            'status' => PrintJobStatus::Pending,
            'payload' => [
                'type' => 'lab_invoice',
                'source' => 'web',
                'copy_for' => 'retake',
                'item_ids' => array_values(array_map('intval', $itemIds)),
                'qr_url' => (string) $invoice->publicReportsUrl(),
            ],
            'attempts' => 0,
        ]);
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
     * Whether the invoice includes a cross match test (billed as "Cross Match" or "Crossmatch").
     */
    private function hasCrossMatch(LabInvoice $invoice): bool
    {
        return $invoice->items()->pluck('test_name')->contains(
            fn (?string $testName): bool => preg_match('/cross\s*-?\s*match/i', (string) $testName) === 1
        );
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
