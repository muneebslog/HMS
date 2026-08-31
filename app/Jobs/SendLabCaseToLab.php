<?php

namespace App\Jobs;

use App\Models\LabInvoice;
use App\Services\LabApiService;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendLabCaseToLab implements ShouldBeUnique, ShouldQueue
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
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $labInvoiceId,
    ) {
        //
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return (string) $this->labInvoiceId;
    }

    /**
     * Execute the job.
     */
    public function handle(LabApiService $labApiService): void
    {
        $invoice = LabInvoice::with(['patient.family', 'items'])->find($this->labInvoiceId);

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

        app(NotificationService::class)->notifyLabCaseSyncFailed($invoice, $exception);
    }
}
