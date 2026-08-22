<?php

namespace App\Services;

use App\Enums\AttendanceRecordStatus;
use App\Enums\DutyAssignmentStatus;
use App\Enums\DutyAssignmentType;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\DutyAssignment;
use App\Models\HealthAideLeave;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceProcessingService
{
    /**
     * Process scheduled duty assignments into attendance records.
     */
    public function processRecentAssignments(?Carbon $since = null): int
    {
        $since ??= now()->subDay()->startOfDay();

        $assignments = DutyAssignment::query()
            ->scheduled()
            ->where('ends_at', '>=', $since)
            ->with(['healthAide', 'shiftTemplate'])
            ->get();

        $processed = 0;

        foreach ($assignments as $assignment) {
            $this->processAssignment($assignment);
            $processed++;
        }

        return $processed;
    }

    /**
     * Process a single duty assignment into an attendance record.
     */
    public function processAssignment(DutyAssignment $assignment): AttendanceRecord
    {
        if ($assignment->status === DutyAssignmentStatus::Cancelled) {
            return AttendanceRecord::query()->updateOrCreate(
                [
                    'health_aide_id' => $assignment->health_aide_id,
                    'duty_assignment_id' => $assignment->id,
                ],
                [
                    'date' => $assignment->date,
                    'scheduled_starts_at' => $assignment->starts_at,
                    'scheduled_ends_at' => $assignment->ends_at,
                    'status' => AttendanceRecordStatus::Absent,
                ],
            );
        }

        if ($this->isOnLeave($assignment)) {
            return $this->saveRecord($assignment, [
                'status' => AttendanceRecordStatus::OnLeave,
                'first_in_at' => null,
                'last_out_at' => null,
                'worked_minutes' => 0,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'overtime_minutes' => 0,
                'payable_minutes' => 0,
            ]);
        }

        $punches = $this->punchesForAssignment($assignment);
        [$firstIn, $lastOut] = $this->pairPunches($punches);

        if ($firstIn === null) {
            return $this->saveRecord($assignment, [
                'status' => AttendanceRecordStatus::Absent,
                'first_in_at' => null,
                'last_out_at' => null,
                'worked_minutes' => 0,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'overtime_minutes' => 0,
                'payable_minutes' => 0,
            ]);
        }

        if ($lastOut === null) {
            return $this->saveRecord($assignment, [
                'status' => AttendanceRecordStatus::Incomplete,
                'first_in_at' => $firstIn->punched_at,
                'last_out_at' => null,
                'worked_minutes' => 0,
                'late_minutes' => $this->lateMinutes($assignment, $firstIn->punched_at),
                'early_leave_minutes' => 0,
                'overtime_minutes' => 0,
                'payable_minutes' => 0,
            ]);
        }

        $metrics = $this->calculateMetrics($assignment, $firstIn->punched_at, $lastOut->punched_at);

        return $this->saveRecord($assignment, [
            'status' => $metrics['status'],
            'first_in_at' => $firstIn->punched_at,
            'last_out_at' => $lastOut->punched_at,
            'worked_minutes' => $metrics['worked_minutes'],
            'late_minutes' => $metrics['late_minutes'],
            'early_leave_minutes' => $metrics['early_leave_minutes'],
            'overtime_minutes' => $metrics['overtime_minutes'],
            'payable_minutes' => $metrics['payable_minutes'],
        ]);
    }

    /**
     * @return Collection<int, AttendancePunch>
     */
    public function punchesForAssignment(DutyAssignment $assignment): Collection
    {
        $preWindow = config('attendance.pre_window_minutes', 30);
        $postWindow = config('attendance.post_window_minutes', 60);

        $windowStart = $assignment->starts_at->copy()->subMinutes($preWindow);
        $windowEnd = $assignment->ends_at->copy()->addMinutes($postWindow);

        return AttendancePunch::query()
            ->where('health_aide_id', $assignment->health_aide_id)
            ->whereBetween('punched_at', [$windowStart, $windowEnd])
            ->orderBy('punched_at')
            ->get();
    }

    /**
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array{0: AttendancePunch|null, 1: AttendancePunch|null}
     */
    public function pairPunches(Collection $punches): array
    {
        if ($punches->isEmpty()) {
            return [null, null];
        }

        $checkIns = $punches->filter(fn (AttendancePunch $punch) => $this->isCheckIn($punch));
        $checkOuts = $punches->filter(fn (AttendancePunch $punch) => $this->isCheckOut($punch));

        if ($checkIns->isNotEmpty() && $checkOuts->isNotEmpty()) {
            return [
                $checkIns->sortBy('punched_at')->first(),
                $checkOuts->sortByDesc('punched_at')->first(),
            ];
        }

        if ($punches->count() >= 2) {
            $sorted = $punches->sortBy('punched_at')->values();

            return [$sorted->first(), $sorted->last()];
        }

        return [$punches->first(), null];
    }

    public function isCheckIn(AttendancePunch $punch): bool
    {
        return in_array($punch->punch_state, [0, 4], true);
    }

    public function isCheckOut(AttendancePunch $punch): bool
    {
        return in_array($punch->punch_state, [1, 5], true);
    }

    /**
     * @return array{
     *     status: AttendanceRecordStatus,
     *     worked_minutes: int,
     *     late_minutes: int,
     *     early_leave_minutes: int,
     *     overtime_minutes: int,
     *     payable_minutes: int
     * }
     */
    public function calculateMetrics(DutyAssignment $assignment, CarbonInterface $firstIn, CarbonInterface $lastOut): array
    {
        $breakMinutes = $assignment->shiftTemplate?->break_minutes ?? 0;
        $graceIn = $assignment->shiftTemplate?->grace_minutes_in ?? 15;
        $graceOut = $assignment->shiftTemplate?->grace_minutes_out ?? 10;

        $workedMinutes = max(0, $firstIn->diffInMinutes($lastOut) - $breakMinutes);
        $lateMinutes = $this->lateMinutes($assignment, $firstIn, $graceIn);
        $earlyLeaveMinutes = $this->earlyLeaveMinutes($assignment, $lastOut, $graceOut);
        $overtimeMinutes = $this->overtimeMinutes($assignment, $lastOut);

        $payableMinutes = $this->roundPayableMinutes($workedMinutes);

        $status = AttendanceRecordStatus::Present;

        if ($lateMinutes > 0) {
            $status = AttendanceRecordStatus::Late;
        }

        if ($earlyLeaveMinutes > 0) {
            $status = AttendanceRecordStatus::EarlyLeave;
        }

        return [
            'status' => $status,
            'worked_minutes' => $workedMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'payable_minutes' => $payableMinutes,
        ];
    }

    private function isOnLeave(DutyAssignment $assignment): bool
    {
        return HealthAideLeave::query()
            ->where('health_aide_id', $assignment->health_aide_id)
            ->whereDate('leave_date', $assignment->date)
            ->exists();
    }

    private function lateMinutes(DutyAssignment $assignment, CarbonInterface $firstIn, ?int $graceIn = null): int
    {
        $graceIn ??= $assignment->shiftTemplate?->grace_minutes_in ?? 15;
        $allowedUntil = $assignment->starts_at->copy()->addMinutes($graceIn);

        if ($firstIn->lessThanOrEqualTo($allowedUntil)) {
            return 0;
        }

        return (int) $allowedUntil->diffInMinutes($firstIn);
    }

    private function earlyLeaveMinutes(DutyAssignment $assignment, CarbonInterface $lastOut, int $graceOut): int
    {
        $allowedFrom = $assignment->ends_at->copy()->subMinutes($graceOut);

        if ($lastOut->greaterThanOrEqualTo($allowedFrom)) {
            return 0;
        }

        return (int) $lastOut->diffInMinutes($allowedFrom);
    }

    private function overtimeMinutes(DutyAssignment $assignment, CarbonInterface $lastOut): int
    {
        if (config('attendance.extra_shifts_count_as_overtime', true)
            && in_array($assignment->assignment_type, [DutyAssignmentType::Extra, DutyAssignmentType::Emergency], true)) {
            return max(0, (int) $assignment->starts_at->diffInMinutes($lastOut));
        }

        $threshold = config('attendance.overtime_after_minutes', 0);
        $overtimeStartsAt = $assignment->ends_at->copy()->addMinutes($threshold);

        if ($lastOut->lessThanOrEqualTo($overtimeStartsAt)) {
            return 0;
        }

        return (int) $overtimeStartsAt->diffInMinutes($lastOut);
    }

    private function roundPayableMinutes(int $minutes): int
    {
        $roundTo = max(1, (int) config('attendance.round_payable_to_minutes', 15));

        return (int) (round($minutes / $roundTo) * $roundTo);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function saveRecord(DutyAssignment $assignment, array $attributes): AttendanceRecord
    {
        $record = AttendanceRecord::query()->updateOrCreate(
            [
                'health_aide_id' => $assignment->health_aide_id,
                'duty_assignment_id' => $assignment->id,
            ],
            array_merge([
                'date' => $assignment->date,
                'scheduled_starts_at' => $assignment->starts_at,
                'scheduled_ends_at' => $assignment->ends_at,
            ], $attributes),
        );

        AttendancePunch::query()
            ->where('health_aide_id', $assignment->health_aide_id)
            ->whereBetween('punched_at', [
                $assignment->starts_at->copy()->subMinutes(config('attendance.pre_window_minutes', 30)),
                $assignment->ends_at->copy()->addMinutes(config('attendance.post_window_minutes', 60)),
            ])
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        return $record;
    }
}
