<?php

namespace App\Console\Commands;

use App\Enums\LabApiStatus;
use App\Jobs\SendLabCaseToLab;
use App\Models\LabApiLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryFailedLabCases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lab:retry-failed-cases';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-dispatch failed lab-case syncs every hour until they succeed.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $invoiceIds = LabApiLog::query()
            ->where('status', LabApiStatus::Failed)
            ->pluck('lab_invoice_id');

        if ($invoiceIds->isEmpty()) {
            $this->info('No failed lab-case syncs to retry.');

            return self::SUCCESS;
        }

        foreach ($invoiceIds as $invoiceId) {
            $this->info("Dispatching lab-case sync for invoice #{$invoiceId}.");
            SendLabCaseToLab::dispatch($invoiceId);
        }

        $jobClass = str_replace('\\', '\\\\', SendLabCaseToLab::class);

        DB::table('failed_jobs')
            ->where('payload', 'like', '%'.$jobClass.'%')
            ->delete();

        $this->info("Queued {$invoiceIds->count()} lab-case sync job(s).");

        return self::SUCCESS;
    }
}
