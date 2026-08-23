<?php

namespace App\Console\Commands;

use App\Services\AttendanceProcessingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('attendance:process')]
#[Description('Rebuild punch pairs and process roster-linked attendance.')]
class ProcessAttendance extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AttendanceProcessingService $processingService): int
    {
        $processed = $processingService->processRecentAssignments();

        $this->info("Processed {$processed} duty assignment(s).");

        return self::SUCCESS;
    }
}
