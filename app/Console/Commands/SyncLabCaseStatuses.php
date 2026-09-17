<?php

namespace App\Console\Commands;

use App\Services\LabApiService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lab:sync-statuses {--limit=50 : Max invoices to sync per run}')]
#[Description('Poll the lab app for in-house result readiness on open lab invoices.')]
class SyncLabCaseStatuses extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LabApiService $labApiService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $synced = $labApiService->syncPendingInvoices($limit);

        $this->info("Synced status for {$synced} lab invoice(s).");

        return self::SUCCESS;
    }
}
