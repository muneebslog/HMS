<?php

namespace App\Services;

use App\Enums\AttendanceRecordStatus;
use App\Enums\DutyAssignmentStatus;
use App\Enums\DutyAssignmentType;
use App\Enums\PunchPairingRole;
use App\Enums\PunchStateSource;
use App\Enums\WorkSessionStatus;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\AttendanceWorkSession;
use App\Models\DutyAssignment;
use App\Models\HealthAide;
use App\Models\HealthAideLeave;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AttendanceProcessingService
{
    /**
     * Rebuild suggested/open work sessions for aides with recent punches.
     */
    public function rebuildRecentSessions(?Carbon $since = null): int
    {
        $since ??= now()->subDays(7)->startOfDay();

        $aideIds = AttendancePunch::query()
            ->whereNotNull('health_aide_id')
            ->where('punched_at', '>=', $since)
            ->distinct()
            ->pluck('health_aide_id');

        $rebuilt = 0;

        foreach ($aideIds as $aideId) {
            $rebuilt += $this->rebuildSessionsForAide((int) $aideId);
        }

        return $rebuilt;
    }

    /**
     * Rebuild non-confirmed work sessions for a health aide using rolling pairing.
     *
     * @return int Number of suggested/open sessions after rebuild
     */
    public function rebuildSessionsForAide(int $healthAideId): int
    {
        $confirmedInPunchIds = AttendanceWorkSession::query()
            ->where('health_aide_id', $healthAideId)
            ->where('status', WorkSessionStatus::Confirmed)
            ->pluck('in_punch_id')
            ->all();

        $confirmedOutPunchIds = AttendanceWorkSession::query()
            ->where('health_aide_id', $healthAideId)
            ->where('status', WorkSessionStatus::Confirmed)
            ->whereNotNull('out_punch_id')
            ->pluck('out_punch_id')
            ->all();

        $lockedPunchIds = array_unique([...$confirmedInPunchIds, ...$confirmedOutPunchIds]);

        AttendanceWorkSession::query()
            ->where('health_aide_id', $healthAideId)
            ->where('status', '!=', WorkSessionStatus::Confirmed)
            ->delete();

        $punches = AttendancePunch::query()
            ->where('health_aide_id', $healthAideId)
            ->where(function ($query) {
                $query->whereNull('pairing_role')
                    ->orWhere('pairing_role', '!=', PunchPairingRole::Ignore);
            })
            ->whereNotIn('id', $lockedPunchIds ?: [0])
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get();

        $expectIn = true;
        $pendingIn = null;
        $created = 0;

        foreach ($punches as $punch) {
            $role = $this->effectivePairingRole($punch, $expectIn);

            if ($role === PunchPairingRole::Ignore) {
                continue;
            }

            if ($role === PunchPairingRole::In) {
                if ($pendingIn !== null) {
                    $this->createOpenSession($healthAideId, $pendingIn);
                    $created++;
                }

                $pendingIn = $punch;
                $expectIn = false;

                continue;
            }

            if ($pendingIn === null) {
                continue;
            }

            $this->createSuggestedSession($healthAideId, $pendingIn, $punch);
            $created++;
            $pendingIn = null;
            $expectIn = true;
        }

        if ($pendingIn !== null) {
            $this->createOpenSession($healthAideId, $pendingIn);
            $created++;
        }

        return $created;
    }

    /**
     * Resolve the effective pairing role for a punch.
     */
    public function effectivePairingRole(AttendancePunch $punch, bool $expectIn = true): PunchPairingRole
    {
        if ($punch->pairing_role !== null) {
            return $punch->pairing_role;
        }

        return $expectIn ? PunchPairingRole::In : PunchPairingRole::Out;
    }

    /**
     * Confirm a suggested or open (with out) session into an attendance record.
     */
    public function confirmSession(AttendanceWorkSession $session, User $user): AttendanceRecord
    {
        $session->loadMissing(['inPunch', 'outPunch', 'dutyAssignment.shiftTemplate']);

        if ($session->status === WorkSessionStatus::Open || $session->out_punch_id === null) {
            abort(422, __('Cannot confirm an open session without a check-out.'));
        }

        $metrics = $this->sessionMetrics($session);

        $session->update([
            'status' => WorkSessionStatus::Confirmed,
            'worked_minutes' => $metrics['worked_minutes'],
            'late_minutes' => $metrics['late_minutes'],
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
            'duty_assignment_id' => $session->duty_assignment_id ?? $this->findOverlappingAssignment($session)?->id,
        ]);

        $session->refresh()->load('dutyAssignment.shiftTemplate');

        $assignment = $session->dutyAssignment;

        if ($assignment !== null) {
            AttendanceRecord::query()
                ->where('duty_assignment_id', $assignment->id)
                ->where(function ($query) use ($session) {
                    $query->whereNull('attendance_work_session_id')
                        ->orWhere('attendance_work_session_id', '!=', $session->id);
                })
                ->where('is_manual_override', false)
                ->delete();
        }

        $record = AttendanceRecord::query()->updateOrCreate(
            ['attendance_work_session_id' => $session->id],
            [
                'health_aide_id' => $session->health_aide_id,
                'duty_assignment_id' => $assignment?->id,
                'date' => $session->starts_at->toDateString(),
                'scheduled_starts_at' => $assignment?->starts_at,
                'scheduled_ends_at' => $assignment?->ends_at,
                'first_in_at' => $session->starts_at,
                'last_out_at' => $session->ends_at,
                'worked_minutes' => $metrics['worked_minutes'],
                'late_minutes' => $metrics['late_minutes'],
                'early_leave_minutes' => $metrics['early_leave_minutes'],
                'overtime_minutes' => $metrics['overtime_minutes'],
                'payable_minutes' => $metrics['payable_minutes'],
                'status' => $metrics['status'],
            ],
        );

        AttendancePunch::query()
            ->whereIn('id', array_filter([$session->in_punch_id, $session->out_punch_id]))
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        return $record;
    }

    /**
     * Unconfirm a session so it can be rebuilt or re-edited.
     */
    public function unconfirmSession(AttendanceWorkSession $session): void
    {
        if ($session->status !== WorkSessionStatus::Confirmed) {
            return;
        }

        AttendanceRecord::query()
            ->where('attendance_work_session_id', $session->id)
            ->where('is_manual_override', false)
            ->delete();

        $session->update([
            'status' => $session->out_punch_id ? WorkSessionStatus::Suggested : WorkSessionStatus::Open,
            'confirmed_by' => null,
            'confirmed_at' => null,
        ]);
    }

    /**
     * Set an admin pairing role override and rebuild sessions for the aide.
     */
    public function setPunchPairingRole(AttendancePunch $punch, ?PunchPairingRole $role, ?string $notes = null): void
    {
        $punch->update([
            'pairing_role' => $role,
            'notes' => $notes ?? $punch->notes,
        ]);

        if ($punch->health_aide_id !== null) {
            $this->rebuildSessionsForAide($punch->health_aide_id);
        }
    }

    /**
     * Create a manual punch and rebuild sessions.
     */
    public function createManualPunch(
        HealthAide $healthAide,
        CarbonInterface $punchedAt,
        ?PunchPairingRole $role,
        User $user,
        ?string $notes = null,
    ): AttendancePunch {
        $punch = AttendancePunch::query()->create([
            'attendance_device_id' => null,
            'device_punch_uid' => 'manual-'.Str::uuid()->toString(),
            'device_user_id' => $healthAide->device_user_id ?? 'manual-'.$healthAide->id,
            'health_aide_id' => $healthAide->id,
            'punched_at' => $punchedAt,
            'verify_type' => null,
            'punch_state' => match ($role) {
                PunchPairingRole::In => 0,
                PunchPairingRole::Out => 1,
                default => null,
            },
            'punch_state_source' => PunchStateSource::Manual,
            'pairing_role' => $role,
            'notes' => $notes,
            'created_by' => $user->id,
        ]);

        $this->rebuildSessionsForAide($healthAide->id);

        return $punch;
    }

    /**
     * Process scheduled duty assignments into attendance records (leave / absent / linked sessions).
     */
    public function processRecentAssignments(?Carbon $since = null): int
    {
        $this->rebuildRecentSessions($since);

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
     * Process a single duty assignment into an attendance record when useful.
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
            return $this->saveAssignmentRecord($assignment, [
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

        $this->rebuildSessionsForAide($assignment->health_aide_id);

        $session = AttendanceWorkSession::query()
            ->where('health_aide_id', $assignment->health_aide_id)
            ->where('status', WorkSessionStatus::Confirmed)
            ->where(function ($query) use ($assignment) {
                $query->where('duty_assignment_id', $assignment->id)
                    ->orWhere(function ($inner) use ($assignment) {
                        $inner->where('starts_at', '<=', $assignment->ends_at->copy()->addMinutes(config('attendance.post_window_minutes', 60)))
                            ->where(function ($ends) use ($assignment) {
                                $ends->whereNull('ends_at')
                                    ->orWhere('ends_at', '>=', $assignment->starts_at->copy()->subMinutes(config('attendance.pre_window_minutes', 30)));
                            });
                    });
            })
            ->orderByDesc('starts_at')
            ->first();

        if ($session !== null) {
            $session->update(['duty_assignment_id' => $assignment->id]);

            $record = AttendanceRecord::query()
                ->where('attendance_work_session_id', $session->id)
                ->first();

            if ($record !== null) {
                $record->update([
                    'duty_assignment_id' => $assignment->id,
                    'scheduled_starts_at' => $assignment->starts_at,
                    'scheduled_ends_at' => $assignment->ends_at,
                ]);

                return $record->fresh();
            }
        }

        $suggested = AttendanceWorkSession::query()
            ->where('health_aide_id', $assignment->health_aide_id)
            ->whereIn('status', [WorkSessionStatus::Suggested, WorkSessionStatus::Open])
            ->where('starts_at', '>=', $assignment->starts_at->copy()->subMinutes(config('attendance.pre_window_minutes', 30)))
            ->where('starts_at', '<=', $assignment->ends_at->copy()->addMinutes(config('attendance.post_window_minutes', 60)))
            ->orderBy('starts_at')
            ->first();

        if ($suggested !== null) {
            $suggested->update(['duty_assignment_id' => $assignment->id]);

            if ($suggested->status === WorkSessionStatus::Open || $suggested->out_punch_id === null) {
                return $this->saveAssignmentRecord($assignment, [
                    'status' => AttendanceRecordStatus::Incomplete,
                    'first_in_at' => $suggested->starts_at,
                    'last_out_at' => null,
                    'worked_minutes' => 0,
                    'late_minutes' => $this->lateMinutes($assignment, $suggested->starts_at),
                    'early_leave_minutes' => 0,
                    'overtime_minutes' => 0,
                    'payable_minutes' => 0,
                ]);
            }

            return $this->saveAssignmentRecord($assignment, [
                'status' => AttendanceRecordStatus::Incomplete,
                'first_in_at' => $suggested->starts_at,
                'last_out_at' => $suggested->ends_at,
                'worked_minutes' => $suggested->worked_minutes,
                'late_minutes' => $suggested->late_minutes,
                'early_leave_minutes' => 0,
                'overtime_minutes' => 0,
                'payable_minutes' => 0,
            ]);
        }

        return $this->saveAssignmentRecord($assignment, [
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
            ->where(function ($query) {
                $query->whereNull('pairing_role')
                    ->orWhere('pairing_role', '!=', PunchPairingRole::Ignore);
            })
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
        $usable = $punches->reject(fn (AttendancePunch $punch) => $punch->isIgnored())->values();

        if ($usable->isEmpty()) {
            return [null, null];
        }

        $expectIn = true;
        $firstIn = null;
        $lastOut = null;

        foreach ($usable as $punch) {
            $role = $this->effectivePairingRole($punch, $expectIn);

            if ($role === PunchPairingRole::In) {
                $firstIn ??= $punch;
                $expectIn = false;

                continue;
            }

            if ($role === PunchPairingRole::Out && $firstIn !== null) {
                $lastOut = $punch;
                $expectIn = true;
            }
        }

        return [$firstIn, $lastOut];
    }

    public function isCheckIn(AttendancePunch $punch): bool
    {
        if ($punch->pairing_role !== null) {
            return $punch->pairing_role === PunchPairingRole::In;
        }

        return in_array($punch->punch_state, [0, 4], true);
    }

    public function isCheckOut(AttendancePunch $punch): bool
    {
        if ($punch->pairing_role !== null) {
            return $punch->pairing_role === PunchPairingRole::Out;
        }

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

        $workedMinutes = max(0, (int) $firstIn->diffInMinutes($lastOut) - $breakMinutes);
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
    private function sessionMetrics(AttendanceWorkSession $session): array
    {
        $assignment = $session->dutyAssignment ?? $this->findOverlappingAssignment($session);

        if ($assignment !== null) {
            $session->setRelation('dutyAssignment', $assignment);

            return $this->calculateMetrics($assignment, $session->starts_at, $session->ends_at);
        }

        $workedMinutes = max(0, (int) $session->starts_at->diffInMinutes($session->ends_at));
        $payableMinutes = $this->roundPayableMinutes($workedMinutes);

        return [
            'status' => AttendanceRecordStatus::Present,
            'worked_minutes' => $workedMinutes,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
            'payable_minutes' => $payableMinutes,
        ];
    }

    private function createOpenSession(int $healthAideId, AttendancePunch $inPunch): AttendanceWorkSession
    {
        $assignment = $this->findOverlappingAssignmentForPunch($healthAideId, $inPunch->punched_at);

        return AttendanceWorkSession::query()->create([
            'health_aide_id' => $healthAideId,
            'in_punch_id' => $inPunch->id,
            'out_punch_id' => null,
            'starts_at' => $inPunch->punched_at,
            'ends_at' => null,
            'status' => WorkSessionStatus::Open,
            'duty_assignment_id' => $assignment?->id,
            'worked_minutes' => 0,
            'late_minutes' => $assignment ? $this->lateMinutes($assignment, $inPunch->punched_at) : 0,
        ]);
    }

    private function createSuggestedSession(
        int $healthAideId,
        AttendancePunch $inPunch,
        AttendancePunch $outPunch,
    ): AttendanceWorkSession {
        $assignment = $this->findOverlappingAssignmentForPunch($healthAideId, $inPunch->punched_at);
        $workedMinutes = max(0, (int) $inPunch->punched_at->diffInMinutes($outPunch->punched_at));
        $lateMinutes = $assignment ? $this->lateMinutes($assignment, $inPunch->punched_at) : 0;

        if ($assignment !== null) {
            $breakMinutes = $assignment->shiftTemplate?->break_minutes ?? 0;
            $workedMinutes = max(0, $workedMinutes - $breakMinutes);
        }

        return AttendanceWorkSession::query()->create([
            'health_aide_id' => $healthAideId,
            'in_punch_id' => $inPunch->id,
            'out_punch_id' => $outPunch->id,
            'starts_at' => $inPunch->punched_at,
            'ends_at' => $outPunch->punched_at,
            'status' => WorkSessionStatus::Suggested,
            'duty_assignment_id' => $assignment?->id,
            'worked_minutes' => $workedMinutes,
            'late_minutes' => $lateMinutes,
        ]);
    }

    private function findOverlappingAssignment(AttendanceWorkSession $session): ?DutyAssignment
    {
        return $this->findOverlappingAssignmentForPunch($session->health_aide_id, $session->starts_at);
    }

    private function findOverlappingAssignmentForPunch(int $healthAideId, CarbonInterface $at): ?DutyAssignment
    {
        $preWindow = config('attendance.pre_window_minutes', 30);
        $postWindow = config('attendance.post_window_minutes', 60);

        return DutyAssignment::query()
            ->scheduled()
            ->where('health_aide_id', $healthAideId)
            ->where('starts_at', '<=', Carbon::parse($at)->copy()->addMinutes($postWindow))
            ->where('ends_at', '>=', Carbon::parse($at)->copy()->subMinutes($preWindow))
            ->with('shiftTemplate')
            ->orderBy('starts_at')
            ->first();
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
    private function saveAssignmentRecord(DutyAssignment $assignment, array $attributes): AttendanceRecord
    {
        $sessionId = $attributes['attendance_work_session_id'] ?? null;
        unset($attributes['attendance_work_session_id']);

        $lookup = $sessionId
            ? ['attendance_work_session_id' => $sessionId]
            : [
                'health_aide_id' => $assignment->health_aide_id,
                'duty_assignment_id' => $assignment->id,
            ];

        return AttendanceRecord::query()->updateOrCreate(
            $lookup,
            array_merge([
                'health_aide_id' => $assignment->health_aide_id,
                'duty_assignment_id' => $assignment->id,
                'attendance_work_session_id' => $sessionId,
                'date' => $assignment->date,
                'scheduled_starts_at' => $assignment->starts_at,
                'scheduled_ends_at' => $assignment->ends_at,
            ], $attributes),
        );
    }
}
