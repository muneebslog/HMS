<?php

namespace App\Jobs;

use App\Models\LabInvoice;
use App\Services\LabApiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncLabCaseStatus implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds after which the unique lock will be released.
     */
    public int $uniqueFor = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $labInvoiceId,
    ) {}

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
        $invoice = LabInvoice::with(['items', 'labApiLog'])->find($this->labInvoiceId);

        if ($invoice === null) {
            return;
        }

        $labApiService->syncInvoiceStatuses($invoice);
    }
}
