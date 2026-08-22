<?php

namespace App\Console\Commands;

use App\Enums\AttendanceRecordStatus;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\DutyAssignment;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('attendance:notify-missing')]
#[Description('Notify admins about missing check-in punches for started duties.')]
class NotifyMissingAttendance extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService): int
    {
        $alertAfterMinutes = config('attendance.missing_punch_alert_minutes', 15);
        $notified = 0;

        $assignments = DutyAssignment::query()
            ->scheduled()
            ->where('starts_at', '<=', now()->subMinutes($alertAfterMinutes))
            ->where('ends_at', '>=', now())
            ->with('healthAide')
            ->get();

        foreach ($assignments as $assignment) {
            $hasInPunch = AttendancePunch::query()
                ->where('health_aide_id', $assignment->health_aide_id)
                ->where('punched_at', '>=', $assignment->starts_at->copy()->subMinutes(config('attendance.pre_window_minutes', 30)))
                ->exists();

            if ($hasInPunch) {
                continue;
            }

            $record = AttendanceRecord::query()
                ->where('duty_assignment_id', $assignment->id)
                ->first();

            if ($record?->status === AttendanceRecordStatus::OnLeave) {
                continue;
            }

            if ($notificationService->notifyAttendanceMissingPunch($assignment)) {
                $notified++;
            }
        }

        $this->info("Notified about {$notified} missing punch(es).");

        return self::SUCCESS;
    }
}
