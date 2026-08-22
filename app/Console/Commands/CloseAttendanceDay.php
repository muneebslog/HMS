<?php

namespace App\Console\Commands;

use App\Enums\AttendanceRecordStatus;
use App\Models\AttendanceRecord;
use App\Models\DutyAssignment;
use App\Services\AttendanceProcessingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('attendance:close-day')]
#[Description('Finalize attendance records for duties that ended recently.')]
class CloseAttendanceDay extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AttendanceProcessingService $processingService): int
    {
        $yesterday = Carbon::yesterday();

        $assignments = DutyAssignment::query()
            ->scheduled()
            ->whereDate('date', '<=', $yesterday)
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($assignments as $assignment) {
            $processingService->processAssignment($assignment);
        }

        AttendanceRecord::query()
            ->where('status', AttendanceRecordStatus::Incomplete)
            ->whereDate('date', '<=', $yesterday)
            ->update(['status' => AttendanceRecordStatus::Incomplete]);

        $this->info("Closed {$assignments->count()} duty assignment(s).");

        return self::SUCCESS;
    }
}
