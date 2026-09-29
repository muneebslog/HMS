<?php

namespace App\Console\Commands;

use App\Services\PartnerLab\PartnerLabSync;
use App\Services\PartnerLab\TestZoneClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('partner-lab:sync {--days= : How many days of partner lab cases to look through (default from config)}')]
#[Description('Read the Test Zone portal and record the partner lab reports waiting to be attached.')]
class SyncPartnerLabReports extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PartnerLabSync $sync): int
    {
        if (! TestZoneClient::isConfigured()) {
            $this->warn('Test Zone sync is off: set TESTZONE_ENABLED, TESTZONE_USERNAME and TESTZONE_PASSWORD.');

            return self::SUCCESS;
        }

        $days = max(1, (int) ($this->option('days') ?: config('services.testzone.days', 7)));

        try {
            $summary = $sync->run($days);
        } catch (Throwable $exception) {
            Log::warning('Partner lab sync failed', ['error' => $exception->getMessage()]);
            $this->error('Partner lab sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Checked {$summary['cases']} case(s), opened {$summary['expanded']}: {$summary['new']} new test(s), {$summary['ready']} newly ready report(s).");

        return self::SUCCESS;
    }
}
