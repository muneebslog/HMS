<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\AttendanceDayReview;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Models\DutySession;
use App\Models\Employee;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns raw machine punches into suggested duty sessions and stores the ones
 * a person confirms. The K60 does not say in or out, so punches are paired in
 * order: first = in, next = out. A session belongs to the day it started.
 *
 * @phpstan-type DutyPlan array{shift_template_id: ?int, scheduled_start: CarbonImmutable, scheduled_end: CarbonImmutable, late_minutes: int, early_leave_minutes: int}
 * @phpstan-type Suggestion array{starts_at: CarbonImmutable, ends_at: ?CarbonImmutable, in_punch_id: int, out_punch_id: ?int, break_minutes: int, duplicate_punch_ids: list<int>, duty_template_ids: list<?int>, duties: list<DutyPlan>, flags: list<string>}
 * @phpstan-type EmployeeDay array{employee: Employee, punches: Collection<int, AttendancePunch>, confirmed: Collection<int, DutySession>, suggestions: list<Suggestion>}
 */
class DutyPairingService
{
    /** @var EloquentCollection<int, ShiftTemplate>|null */
    private ?EloquentCollection $templates = null;

    /**
     * Everything the pairing desk needs for one day, keyed by employee id.
     *
     * Includes every active employee who punches the machine, whether or not
     * they have punches that day.
     *
     * @return Collection<int, EmployeeDay>
     */
    public function day(CarbonImmutable $date): Collection
    {
        [$windowStart, $windowEnd] = $this->window($date);

        $employees = Employee::query()
            ->active()
            ->tracksAttendance()
            ->with('defaultShiftTemplate')
            ->orderBy('name')
            ->get();

        $deviceUserEmployee = AttendanceDeviceUser::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->pluck('employee_id', 'id');

        $punchesByEmployee = AttendancePunch::query()
            ->whereIn('attendance_device_user_id', $deviceUserEmployee->keys())
            ->whereBetween('punched_at', [$windowStart, $windowEnd])
            ->orderBy('punched_at')
            ->get()
            ->groupBy(fn (AttendancePunch $punch): int => $deviceUserEmployee[$punch->attendance_device_user_id]);

        $sessionsByEmployee = DutySession::query()
            ->with('duties.shiftTemplate')
            ->whereIn('employee_id', $employees->modelKeys())
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart)
            ->orderBy('starts_at')
            ->get()
            ->groupBy('employee_id');

        return $employees->mapWithKeys(function (Employee $employee) use ($date, $punchesByEmployee, $sessionsByEmployee): array {
            $punches = $punchesByEmployee->get($employee->id, collect());
            $sessions = $sessionsByEmployee->get($employee->id, collect());

            return [$employee->id => [
                'employee' => $employee,
                'punches' => $punches->values(),
                'confirmed' => $sessions->filter(fn (DutySession $session): bool => $session->duty_date->toDateString() === $date->toDateString())->values(),
                'suggestions' => $this->suggest($employee, $date, $punches, $sessions),
            ]];
        });
    }

    /**
     * Suggested sessions for one employee on one day.
     *
     * @return list<Suggestion>
     */
    public function suggestionsFor(Employee $employee, CarbonImmutable $date): array
    {
        return $this->day($date)->get($employee->id)['suggestions'] ?? [];
    }

    /**
     * Pair the punches that are not yet part of a confirmed session.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @param  Collection<int, DutySession>  $sessions
     * @return list<Suggestion>
     */
    private function suggest(Employee $employee, CarbonImmutable $date, Collection $punches, Collection $sessions): array
    {
        $free = $punches
            ->reject(fn (AttendancePunch $punch): bool => $punch->isIgnored())
            ->reject(fn (AttendancePunch $punch): bool => $sessions->contains(
                fn (DutySession $session): bool => in_array($punch->id, [$session->in_punch_id, $session->out_punch_id], true)
                    || ($punch->punched_at->gte($session->starts_at) && $punch->punched_at->lte($session->ends_at))
            ))
            ->values();

        $duplicateWindow = AppSetting::attendanceRule(AppSetting::DuplicatePunchMinutes);
        $kept = [];
        $duplicatesOf = [];

        foreach ($free as $punch) {
            $previous = end($kept) ?: null;

            if ($previous !== null && $previous->punched_at->diffInMinutes($punch->punched_at) < $duplicateWindow) {
                $duplicatesOf[$previous->id][] = $punch->id;

                continue;
            }

            $kept[] = $punch;
        }

        $maxMinutes = AppSetting::attendanceRule(AppSetting::MaxSessionHours) * 60;
        $pairs = [];

        for ($index = 0; $index < count($kept); $index++) {
            $in = $kept[$index];
            $out = $kept[$index + 1] ?? null;

            if ($out !== null && $in->punched_at->diffInMinutes($out->punched_at) <= $maxMinutes) {
                $pairs[] = [$in, $out];
                $index++;
            } else {
                $pairs[] = [$in, null];
            }
        }

        $pairs = $this->joinBreaks($pairs);
        $longMinutes = AppSetting::attendanceRule(AppSetting::LongSessionHours) * 60;
        $suggestions = [];

        foreach ($pairs as $pair) {
            /** @var AttendancePunch $in */
            $in = $pair['in'];
            $startsAt = CarbonImmutable::parse($in->punched_at);

            if ($startsAt->toDateString() !== $date->toDateString()) {
                continue;
            }

            $endsAt = $pair['out'] ? CarbonImmutable::parse($pair['out']->punched_at) : null;
            $flags = [];

            if ($endsAt === null) {
                $flags[] = 'missing_out';
            } elseif ($startsAt->diffInMinutes($endsAt) > $longMinutes) {
                $flags[] = 'long';
            }

            if ($pair['break_minutes'] > 0) {
                $flags[] = 'break';
            }

            $templateIds = $endsAt ? $this->planDuties($employee, $startsAt, $endsAt) : [];
            $duplicateIds = array_values(collect($pair['punch_ids'])->flatMap(fn (int $id): array => $duplicatesOf[$id] ?? [])->all());

            $suggestions[] = [
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'in_punch_id' => $in->id,
                'out_punch_id' => $pair['out']?->id,
                'break_minutes' => $pair['break_minutes'],
                'duplicate_punch_ids' => $duplicateIds,
                'duty_template_ids' => $templateIds,
                'duties' => $endsAt ? $this->buildDuties($startsAt, $endsAt, $templateIds) : [],
                'flags' => $flags,
            ];
        }

        return $suggestions;
    }

    /**
     * Join back-to-back sessions separated by a short break into one session.
     *
     * @param  list<array{0: AttendancePunch, 1: ?AttendancePunch}>  $pairs
     * @return list<array{in: AttendancePunch, out: ?AttendancePunch, break_minutes: int, punch_ids: list<int>}>
     */
    private function joinBreaks(array $pairs): array
    {
        $breakWindow = AppSetting::attendanceRule(AppSetting::BreakMergeMinutes);
        $joined = [];

        foreach ($pairs as [$in, $out]) {
            $last = array_key_last($joined);

            if ($last !== null && $joined[$last]['out'] !== null && $out !== null
                && $joined[$last]['out']->punched_at->diffInMinutes($in->punched_at) <= $breakWindow) {
                $joined[$last]['break_minutes'] += (int) $joined[$last]['out']->punched_at->diffInMinutes($in->punched_at);
                $joined[$last]['out'] = $out;
                $joined[$last]['punch_ids'][] = $in->id;
                $joined[$last]['punch_ids'][] = $out->id;

                continue;
            }

            $joined[] = ['in' => $in, 'out' => $out, 'break_minutes' => 0, 'punch_ids' => array_values(array_filter([$in->id, $out?->id]))];
        }

        return $joined;
    }

    /**
     * Pick the shifts a session most likely covered, in order.
     *
     * Walks from arrival to leaving, each time taking the shift that starts
     * closest to where the previous one ended and that the person was present
     * for at least half of. Their usual shift wins near-ties. No match = one
     * custom duty (null).
     *
     * @return list<?int>
     */
    public function planDuties(Employee $employee, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $templateIds = [];
        $cursor = $startsAt;

        for ($guard = 0; $guard < 8; $guard++) {
            $best = null;
            $bestScore = null;

            foreach ($this->templates() as $template) {
                for ($day = $cursor->subDay()->startOfDay(); $day->lte($endsAt); $day = $day->addDay()) {
                    [$occurrenceStart, $occurrenceEnd] = $template->occurrenceOn($day);
                    $overlap = $this->overlapMinutes($occurrenceStart, $occurrenceEnd, $cursor, $endsAt);
                    $length = $occurrenceStart->diffInMinutes($occurrenceEnd);

                    if ($occurrenceEnd->lte($cursor) || $length <= 0 || $overlap * 2 < $length) {
                        continue;
                    }

                    $score = abs($occurrenceStart->diffInMinutes($cursor, false));

                    if ($template->id === $employee->default_shift_template_id) {
                        $score -= 90;
                    }

                    if ($bestScore === null || $score < $bestScore) {
                        $best = [$template, $occurrenceEnd];
                        $bestScore = $score;
                    }
                }
            }

            if ($best === null) {
                break;
            }

            $templateIds[] = $best[0]->id;
            $cursor = $best[1];

            if ($cursor->gte($endsAt)) {
                break;
            }
        }

        return $templateIds === [] ? [null] : $templateIds;
    }

    /**
     * Turn a list of chosen shifts into dated duties with late / early minutes.
     *
     * @param  list<?int>  $templateIds  null = custom duty using the actual times
     * @return list<DutyPlan>
     */
    public function buildDuties(CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $templateIds): array
    {
        $templateIds = $templateIds === [] ? [null] : $templateIds;
        $slots = [];
        $cursor = $startsAt;

        foreach ($templateIds as $index => $templateId) {
            $template = $templateId !== null ? $this->templates()->find($templateId) ?? ShiftTemplate::query()->find($templateId) : null;

            if ($template === null) {
                $slots[$index] = null;

                continue;
            }

            [$scheduledStart, $scheduledEnd] = $this->nearestOccurrence($template, $cursor);
            $slots[$index] = [$template->id, $scheduledStart, $scheduledEnd];
            $cursor = $scheduledEnd;
        }

        foreach ($slots as $index => $slot) {
            if ($slot !== null) {
                continue;
            }

            $previousEnd = $index > 0 && $slots[$index - 1] !== null ? $slots[$index - 1][2] : $startsAt;
            $nextStart = collect($slots)->slice($index + 1)->first(fn ($candidate) => $candidate !== null)[1] ?? $endsAt;
            $slots[$index] = [null, $previousEnd, $nextStart->max($previousEnd)];
        }

        $lastIndex = count($slots) - 1;

        return array_map(fn (array $slot, int $index): array => [
            'shift_template_id' => $slot[0],
            'scheduled_start' => $slot[1],
            'scheduled_end' => $slot[2],
            'late_minutes' => $index === 0 ? max(0, (int) $slot[1]->diffInMinutes($startsAt, false)) : 0,
            'early_leave_minutes' => $index === $lastIndex ? max(0, (int) $endsAt->diffInMinutes($slot[2], false)) : 0,
        ], $slots, array_keys($slots));
    }

    /**
     * Store a session the desk confirmed, with its duties.
     *
     * @param  array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, in_punch_id: ?int, out_punch_id: ?int, break_minutes?: int, notes?: ?string, duty_template_ids: list<?int>}  $data
     */
    public function confirm(Employee $employee, array $data, User $user): DutySession
    {
        if ($data['ends_at']->lte($data['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => __('Leaving time must be after arriving time.')]);
        }

        $punchIds = array_values(array_filter([$data['in_punch_id'], $data['out_punch_id']]));

        if ($punchIds !== []) {
            $ownPunches = AttendancePunch::query()
                ->whereKey($punchIds)
                ->whereIn('attendance_device_user_id', $employee->attendanceDeviceUsers()->select('id'))
                ->count();

            $usedElsewhere = DutySession::query()
                ->where(fn ($query) => $query->whereIn('in_punch_id', $punchIds)->orWhereIn('out_punch_id', $punchIds))
                ->exists();

            if ($ownPunches !== count($punchIds) || $usedElsewhere) {
                throw ValidationException::withMessages(['in_punch_id' => __('These punches do not belong to :name or are already paired.', ['name' => $employee->name])]);
            }
        }

        $overlaps = $employee->dutySessions()
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['starts_at' => __('This overlaps a session that is already confirmed.')]);
        }

        return DB::transaction(function () use ($employee, $data, $user): DutySession {
            $session = $employee->dutySessions()->create([
                'duty_date' => $data['starts_at']->toDateString(),
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'in_punch_id' => $data['in_punch_id'],
                'out_punch_id' => $data['out_punch_id'],
                'break_minutes' => $data['break_minutes'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ]);

            foreach ($this->buildDuties($data['starts_at'], $data['ends_at'], $data['duty_template_ids']) as $order => $duty) {
                $session->duties()->create($duty + ['sort_order' => $order]);
            }

            return $session->load('duties.shiftTemplate');
        });
    }

    /**
     * Confirm a suggestion exactly as the engine proposed it.
     *
     * @param  Suggestion  $suggestion
     */
    public function confirmSuggestion(Employee $employee, array $suggestion, User $user): DutySession
    {
        if ($suggestion['ends_at'] === null) {
            throw ValidationException::withMessages(['ends_at' => __('Add the missing leaving time first.')]);
        }

        return $this->confirm($employee, [
            'starts_at' => $suggestion['starts_at'],
            'ends_at' => $suggestion['ends_at'],
            'in_punch_id' => $suggestion['in_punch_id'],
            'out_punch_id' => $suggestion['out_punch_id'],
            'break_minutes' => $suggestion['break_minutes'],
            'duty_template_ids' => $suggestion['duty_template_ids'],
        ], $user);
    }

    /**
     * Confirm every suggestion on the day that needs no human judgement.
     */
    public function confirmCleanSuggestions(CarbonImmutable $date, User $user): int
    {
        $confirmed = 0;

        foreach ($this->day($date) as $row) {
            foreach ($row['suggestions'] as $suggestion) {
                if (! $this->needsAttention($suggestion)) {
                    $this->confirmSuggestion($row['employee'], $suggestion, $user);
                    $confirmed++;
                }
            }
        }

        return $confirmed;
    }

    /**
     * Whether a suggestion must be looked at before it can be confirmed.
     *
     * @param  Suggestion  $suggestion
     */
    public function needsAttention(array $suggestion): bool
    {
        return array_intersect($suggestion['flags'], ['missing_out', 'long']) !== [];
    }

    /**
     * Send a confirmed session back to the suggestions.
     */
    public function unconfirm(DutySession $session): void
    {
        $session->delete();
    }

    /**
     * Set a punch aside so pairing skips it.
     */
    public function ignorePunch(AttendancePunch $punch, User $user, string $reason): void
    {
        $punch->update(['ignored_at' => now(), 'ignored_by' => $user->id, 'ignore_reason' => $reason]);
    }

    /**
     * Bring an ignored punch back into pairing.
     */
    public function restorePunch(AttendancePunch $punch): void
    {
        $punch->update(['ignored_at' => null, 'ignored_by' => null, 'ignore_reason' => null]);
    }

    /**
     * Number of unconfirmed suggestions left on the day.
     */
    public function pendingCount(CarbonImmutable $date): int
    {
        return $this->day($date)->sum(fn (array $row): int => count($row['suggestions']));
    }

    /**
     * Mark the day as fully paired. Refused while suggestions are left.
     */
    public function markReviewed(CarbonImmutable $date, User $user): AttendanceDayReview
    {
        $pending = $this->pendingCount($date);

        if ($pending > 0) {
            throw ValidationException::withMessages(['review' => trans_choice('{1} 1 session still needs confirming.|[2,*] :count sessions still need confirming.', $pending)]);
        }

        return AttendanceDayReview::query()->updateOrCreate(
            ['review_date' => $date->toDateString()],
            ['reviewed_by' => $user->id, 'reviewed_at' => now()],
        );
    }

    /**
     * Punches considered for a day: from the previous midnight (so a session
     * still open from yesterday pairs correctly) until the longest possible
     * session that starts today could end.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(CarbonImmutable $date): array
    {
        $day = $date->startOfDay();

        return [$day->subDay(), $day->addDay()->addHours(AppSetting::attendanceRule(AppSetting::MaxSessionHours))];
    }

    /**
     * Active shift templates, loaded once per service instance.
     *
     * @return EloquentCollection<int, ShiftTemplate>
     */
    public function templates(): EloquentCollection
    {
        return $this->templates ??= ShiftTemplate::query()->active()->get();
    }

    /**
     * The occurrence of a shift that starts closest to the given moment.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function nearestOccurrence(ShiftTemplate $template, CarbonImmutable $moment): array
    {
        return collect([$moment->subDay(), $moment, $moment->addDay()])
            ->map(fn (CarbonImmutable $day): array => $template->occurrenceOn($day))
            ->sortBy(fn (array $occurrence): float => abs($occurrence[0]->diffInMinutes($moment, false)))
            ->first();
    }

    private function overlapMinutes(CarbonImmutable $startA, CarbonImmutable $endA, CarbonImmutable $startB, CarbonImmutable $endB): int
    {
        $start = $startA->max($startB);
        $end = $endA->min($endB);

        return $end->gt($start) ? (int) $start->diffInMinutes($end) : 0;
    }
}
