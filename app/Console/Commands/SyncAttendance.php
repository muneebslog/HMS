<?php

namespace App\Console\Commands;

use App\Services\AttendanceProcessingService;
use App\Services\ZktecoSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('attendance:sync')]
#[Description('Sync attendance punches from the ZKTeco device.')]
class SyncAttendance extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ZktecoSyncService $syncService, AttendanceProcessingService $processingService): int
    {
        $result = $syncService->sync();

        $this->info("Imported {$result['imported']} of {$result['total']} punch(es).");

        $processed = $processingService->processRecentAssignments();

        $this->info("Processed {$processed} duty assignment(s).");

        return self::SUCCESS;
    }
}
