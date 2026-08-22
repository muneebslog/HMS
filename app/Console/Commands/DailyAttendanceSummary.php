<?php

namespace App\Console\Commands;

use App\Enums\AttendanceRecordStatus;
use App\Models\AttendanceRecord;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('attendance:daily-summary')]
#[Description('Send a daily attendance summary for yesterday.')]
class DailyAttendanceSummary extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService): int
    {
        $date = Carbon::yesterday()->toDateString();

        $records = AttendanceRecord::query()
            ->with('healthAide')
            ->whereDate('date', $date)
            ->get();

        $absences = $records->where('status', AttendanceRecordStatus::Absent)->count();
        $lates = $records->where('late_minutes', '>', 0)->count();
        $incomplete = $records->where('status', AttendanceRecordStatus::Incomplete)->count();

        if ($absences === 0 && $lates === 0 && $incomplete === 0) {
            $this->info('No attendance issues to report for yesterday.');

            return self::SUCCESS;
        }

        $notificationService->notifyAttendanceDailySummary($date, $absences, $lates, $incomplete);

        $this->info("Sent daily attendance summary for {$date}.");

        return self::SUCCESS;
    }
}
