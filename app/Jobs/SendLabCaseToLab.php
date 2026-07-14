<?php

namespace App\Jobs;

use App\Models\AdminNotification;
use App\Models\LabInvoice;
use App\Services\LabApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendLabCaseToLab implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var list<int>
     */
    public array $backoff = [30, 60, 120, 300];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $labInvoiceId,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(LabApiService $labApiService): void
    {
        $invoice = LabInvoice::find($this->labInvoiceId);

        if ($invoice === null) {
            return;
        }

        if (! $labApiService->sendLabCase($invoice)) {
            throw new \RuntimeException(__('Failed to sync lab invoice :invoice to lab app.', [
                'invoice' => $invoice->invoice_number,
            ]));
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $invoice = LabInvoice::find($this->labInvoiceId);

        if ($invoice === null) {
            return;
        }

        $alreadyNotified = AdminNotification::where('type', 'lab_case_sync_failed')
            ->whereJsonContains('metadata', ['lab_invoice_id' => $invoice->id])
            ->exists();

        if ($alreadyNotified) {
            return;
        }

        AdminNotification::create([
            'user_id' => $invoice->created_by,
            'type' => 'lab_case_sync_failed',
            'title' => __('Lab case sync failed'),
            'message' => __('Lab invoice :invoice could not be sent to the lab app after multiple attempts: :error', [
                'invoice' => $invoice->invoice_number,
                'error' => $exception->getMessage(),
            ]),
            'actionable_url' => route('reception.invoices'),
            'metadata' => [
                'lab_invoice_id' => $invoice->id,
                'error' => $exception->getMessage(),
            ],
        ]);
    }
}
