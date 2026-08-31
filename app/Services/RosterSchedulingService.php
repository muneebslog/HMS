<?php

namespace App\Services;

use App\Enums\DutyAssignmentStatus;
use App\Enums\DutyAssignmentType;
use App\Models\DutyAssignment;
use App\Models\DutyShiftTemplate;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RosterSchedulingService
{
    public const MAX_DUTY_HOURS = 24;

    /**
     * Build duty start/end datetimes from explicit inputs or a shift template.
     *
     * @return array{starts_at: Carbon, ends_at: Carbon, date: string}
     */
    public function resolveWindow(
        Carbon $startDateTime,
        ?Carbon $endDateTime,
        ?DutyShiftTemplate $template = null,
    ): array {
        if ($template !== null) {
            $window = $template->windowForDate($startDateTime->copy()->startOfDay());

            return [
                'starts_at' => $window['starts_at'],
                'ends_at' => $window['ends_at'],
                'date' => $startDateTime->toDateString(),
            ];
        }

        $startsAt = $startDateTime->copy();

        if ($endDateTime === null) {
            $endsAt = $startsAt->copy()->addHours(8);
        } else {
            $endsAt = $endDateTime->copy();
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'dutyEndAt' => __('Duty end must be after duty start.'),
            ]);
        }

        if ($startsAt->diffInHours($endsAt) > self::MAX_DUTY_HOURS) {
            throw ValidationException::withMessages([
                'dutyEndAt' => __('Duty duration cannot exceed :hours hours.', ['hours' => self::MAX_DUTY_HOURS]),
            ]);
        }

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'date' => $startsAt->toDateString(),
        ];
    }

    /**
     * @param  list<int>  $healthAideIds
     * @param  list<int>  $weekdays  ISO weekday numbers (1=Mon … 7=Sun)
     * @return list<DutyAssignment>
     */
    public function createRecurringAssignments(
        array $healthAideIds,
        Carbon $dateFrom,
        Carbon $dateTo,
        array $weekdays,
        Carbon $dutyStartAt,
        ?Carbon $dutyEndAt,
        ?DutyShiftTemplate $template,
        int $dutyLocationId,
        DutyAssignmentType $assignmentType,
        ?string $notes,
        User $createdBy,
    ): array {
        $created = [];

        for ($date = $dateFrom->copy()->startOfDay(); $date->lte($dateTo); $date->addDay()) {
            if (! in_array($date->isoWeekday(), $weekdays, true)) {
                continue;
            }

            $occurrenceStart = $date->copy()
                ->setTime($dutyStartAt->hour, $dutyStartAt->minute, $dutyStartAt->second);

            $occurrenceEnd = $dutyEndAt !== null
                ? $date->copy()->setTime($dutyEndAt->hour, $dutyEndAt->minute, $dutyEndAt->second)
                : null;

            if ($occurrenceEnd !== null && $occurrenceEnd->lessThanOrEqualTo($occurrenceStart)) {
                $occurrenceEnd->addDay();
            }

            $window = $template !== null
                ? $this->resolveWindow($occurrenceStart, null, $template)
                : $this->resolveWindow($occurrenceStart, $occurrenceEnd, null);

            foreach ($healthAideIds as $aideId) {
                if ($this->hasOverrideForDate($aideId, $date)) {
                    continue;
                }

                $created[] = DutyAssignment::query()->create([
                    'health_aide_id' => $aideId,
                    'duty_shift_template_id' => $template?->id,
                    'duty_location_id' => $dutyLocationId,
                    'date' => $window['date'],
                    'starts_at' => $window['starts_at'],
                    'ends_at' => $window['ends_at'],
                    'assignment_type' => $assignmentType,
                    'is_override' => false,
                    'notes' => $notes,
                    'status' => DutyAssignmentStatus::Scheduled,
                    'created_by' => $createdBy->id,
                ]);
            }
        }

        return $created;
    }

    public function createOrUpdateOverride(
        int $healthAideId,
        Carbon $dutyStartAt,
        Carbon $dutyEndAt,
        ?DutyShiftTemplate $template,
        int $dutyLocationId,
        DutyAssignmentType $assignmentType,
        ?string $notes,
        User $createdBy,
        ?int $editingAssignmentId = null,
    ): DutyAssignment {
        $window = $this->resolveWindow($dutyStartAt, $dutyEndAt, $template);

        if ($editingAssignmentId !== null) {
            $assignment = DutyAssignment::query()->findOrFail($editingAssignmentId);
            $assignment->update([
                'duty_shift_template_id' => $template?->id,
                'duty_location_id' => $dutyLocationId,
                'date' => $window['date'],
                'starts_at' => $window['starts_at'],
                'ends_at' => $window['ends_at'],
                'assignment_type' => $assignmentType,
                'is_override' => true,
                'notes' => $notes,
            ]);

            return $assignment->refresh();
        }

        $this->cancelScheduledAssignmentsForDate($healthAideId, Carbon::parse($window['date']));

        return DutyAssignment::query()->create([
            'health_aide_id' => $healthAideId,
            'duty_shift_template_id' => $template?->id,
            'duty_location_id' => $dutyLocationId,
            'date' => $window['date'],
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'assignment_type' => $assignmentType,
            'is_override' => true,
            'notes' => $notes,
            'status' => DutyAssignmentStatus::Scheduled,
            'created_by' => $createdBy->id,
        ]);
    }

    /**
     * @return Collection<int, DutyAssignment>
     */
    public function assignmentsOverlappingWeek(Carbon $weekStart, ?int $healthAideId = null): Collection
    {
        $rangeStart = $weekStart->copy()->startOfDay();
        $rangeEnd = $weekStart->copy()->addDays(6)->endOfDay();

        return DutyAssignment::query()
            ->scheduled()
            ->when($healthAideId !== null, fn ($query) => $query->where('health_aide_id', $healthAideId))
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->with(['healthAide', 'shiftTemplate', 'dutyLocation'])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Split an assignment into visual segments for each day column in a week.
     *
     * @return list<array{assignment: DutyAssignment, day: string, top_percent: float, height_percent: float, segment_key: string}>
     */
    public function calendarSegmentsForWeek(Collection $assignments, Carbon $weekStart): array
    {
        $weekDays = collect(range(0, 6))->map(
            fn (int $offset) => $weekStart->copy()->addDays($offset)->toDateString(),
        );

        $segments = [];

        foreach ($assignments as $assignment) {
            foreach ($weekDays as $dayString) {
                $position = $this->positionEvent(
                    $assignment->starts_at,
                    $assignment->ends_at,
                    Carbon::parse($dayString),
                );

                if ($position === null) {
                    continue;
                }

                $segments[] = [
                    'assignment' => $assignment,
                    'day' => $dayString,
                    'top_percent' => $position['top_percent'],
                    'height_percent' => $position['height_percent'],
                    'segment_key' => $assignment->id.'-'.$dayString,
                ];
            }
        }

        return $segments;
    }

    /**
     * @return array{top_percent: float, height_percent: float}|null
     */
    public function positionEvent(CarbonInterface $startsAt, CarbonInterface $endsAt, CarbonInterface $dayColumn): ?array
    {
        $startsAt = Carbon::parse($startsAt);
        $endsAt = Carbon::parse($endsAt);
        $dayColumn = Carbon::parse($dayColumn);

        $dayStart = $dayColumn->copy()->startOfDay();
        $dayEnd = $dayColumn->copy()->endOfDay();

        if ($endsAt->lessThanOrEqualTo($dayStart) || $startsAt->greaterThanOrEqualTo($dayEnd)) {
            return null;
        }

        $segmentStart = $startsAt->greaterThan($dayStart) ? $startsAt : $dayStart;
        $segmentEnd = $endsAt->lessThan($dayEnd) ? $endsAt : $dayEnd;

        if ($segmentEnd->lessThanOrEqualTo($segmentStart)) {
            return null;
        }

        $minutesInDay = 24 * 60;
        $startMinutes = $dayStart->diffInMinutes($segmentStart);
        $endMinutes = $dayStart->diffInMinutes($segmentEnd);

        if ($endsAt->greaterThan($dayEnd)) {
            $endMinutes = $minutesInDay;
        }

        if ($endMinutes <= $startMinutes) {
            return null;
        }

        return [
            'top_percent' => ($startMinutes / $minutesInDay) * 100,
            'height_percent' => (($endMinutes - $startMinutes) / $minutesInDay) * 100,
        ];
    }

    public function hasOverrideForDate(int $healthAideId, Carbon $date): bool
    {
        return DutyAssignment::query()
            ->scheduled()
            ->where('health_aide_id', $healthAideId)
            ->whereDate('date', $date->toDateString())
            ->where('is_override', true)
            ->exists();
    }

    private function cancelScheduledAssignmentsForDate(int $healthAideId, Carbon $date): void
    {
        DutyAssignment::query()
            ->scheduled()
            ->where('health_aide_id', $healthAideId)
            ->whereDate('date', $date->toDateString())
            ->update(['status' => DutyAssignmentStatus::Cancelled]);
    }
}
