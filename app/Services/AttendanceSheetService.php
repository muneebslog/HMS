<?php

namespace App\Services;

use App\Enums\LeaveKind;
use App\Enums\StaffCategory;
use App\Models\AppSetting;
use App\Models\AttendanceDayReview;
use App\Models\AttendanceDuty;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\StaffLeave;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the month grid: one row per person, one cell per day, from
 * confirmed duties, leaves and holidays only.
 *
 * @phpstan-type SheetCell array{code: string, tone: string, title: string, late: bool}
 * @phpstan-type SheetRow array{employee: Employee, cells: list<SheetCell>, duties: int, paid_leave: float, unpaid_leave: float, absent: float, late: int, expected: int}
 */
class AttendanceSheetService
{
    /**
     * @return array{days: list<CarbonImmutable>, rows: Collection<int, SheetRow>}
     */
    public function month(CarbonImmutable $month, ?StaffCategory $category = null): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $today = CarbonImmutable::today();
        $graceMinutes = AppSetting::attendanceRule(AppSetting::LateGraceMinutes);

        $employees = Employee::query()
            ->active()
            ->tracksAttendance()
            ->when($category, fn ($query) => $query->where('staff_category', $category))
            ->orderBy('name')
            ->get();

        $duties = AttendanceDuty::query()
            ->with(['session:id,employee_id,duty_date', 'shiftTemplate:id,name'])
            ->whereHas('session', fn ($query) => $query
                ->whereIn('employee_id', $employees->modelKeys())
                ->whereDate('duty_date', '>=', $start)
                ->whereDate('duty_date', '<=', $end))
            ->get()
            ->groupBy(fn (AttendanceDuty $duty): string => $duty->session->employee_id.'|'.$duty->session->duty_date->toDateString());

        $leaves = StaffLeave::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereDate('leave_date', '>=', $start)
            ->whereDate('leave_date', '<=', $end)
            ->get()
            ->keyBy(fn (StaffLeave $leave): string => $leave->employee_id.'|'.$leave->leave_date->toDateString());

        $holidays = Holiday::query()
            ->whereDate('holiday_date', '>=', $start)
            ->whereDate('holiday_date', '<=', $end)
            ->get()
            ->keyBy(fn (Holiday $holiday): string => $holiday->holiday_date->toDateString());

        $reviewed = AttendanceDayReview::query()
            ->whereDate('review_date', '>=', $start)
            ->whereDate('review_date', '<=', $end)
            ->get()
            ->map(fn (AttendanceDayReview $review): string => $review->review_date->toDateString())
            ->flip();

        $days = array_map(fn (int $offset): CarbonImmutable => $start->addDays($offset), range(0, $end->day - 1));

        $rows = $employees->map(function (Employee $employee) use ($days, $duties, $leaves, $holidays, $reviewed, $today, $graceMinutes, $month): array {
            $totals = ['duties' => 0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0, 'absent' => 0.0, 'late' => 0];
            $cells = [];

            foreach ($days as $day) {
                $key = $day->toDateString();
                $dayDuties = $duties->get($employee->id.'|'.$key, collect());
                $leave = $leaves->get($employee->id.'|'.$key);
                $late = $dayDuties->contains(fn (AttendanceDuty $duty): bool => $duty->late_minutes > $graceMinutes);

                $totals['duties'] += $dayDuties->count();
                $totals['late'] += $late ? 1 : 0;

                if ($leave !== null) {
                    if ($leave->kind === LeaveKind::Uninformed) {
                        $totals['absent'] += $leave->days;
                    } else {
                        $totals['paid_leave'] += $leave->paid_days;
                        $totals['unpaid_leave'] += $leave->unpaidDays();
                    }
                }

                $cells[] = $this->cell($dayDuties, $leave, $holidays->get($key), $day, $today, isset($reviewed[$key])) + ['late' => $late];
            }

            return ['employee' => $employee, 'cells' => $cells, 'expected' => $employee->expectedDutiesFor($month)] + $totals;
        });

        return ['days' => $days, 'rows' => $rows];
    }

    /**
     * @param  Collection<int, AttendanceDuty>  $duties
     * @return array{code: string, tone: string, title: string}
     */
    private function cell(Collection $duties, ?StaffLeave $leave, ?Holiday $holiday, CarbonImmutable $day, CarbonImmutable $today, bool $reviewed): array
    {
        if ($duties->count() > 1) {
            return ['code' => (string) $duties->count(), 'tone' => 'double', 'title' => $duties->map->label()->join(' + ')];
        }

        if ($duties->count() === 1) {
            $duty = $duties->first();
            $code = $duty->shiftTemplate ? mb_strtoupper(mb_substr($duty->shiftTemplate->name, 0, 1)) : 'C';
            $tone = match ($code) {
                'N' => 'night',
                'C' => 'custom',
                default => 'shift',
            };

            if ($leave?->isHalfDay()) {
                return ['code' => $code.'½', 'tone' => $tone, 'title' => $duty->label().' + '.__('half day leave')];
            }

            return ['code' => $code, 'tone' => $tone, 'title' => $duty->label()];
        }

        if ($leave !== null) {
            $half = $leave->isHalfDay() ? '½' : '';

            return match (true) {
                $leave->kind === LeaveKind::Uninformed => ['code' => 'A'.$half, 'tone' => 'absent', 'title' => __('Absent (uninformed)')],
                $leave->paid_days >= $leave->days => ['code' => 'L'.$half, 'tone' => 'leave', 'title' => __('Paid leave')],
                default => ['code' => 'U'.$half, 'tone' => 'unpaid', 'title' => __('Unpaid leave (allowance used up)')],
            };
        }

        if ($holiday !== null) {
            return ['code' => 'H', 'tone' => 'holiday', 'title' => $holiday->name];
        }

        if ($day->gte($today)) {
            return ['code' => '', 'tone' => 'future', 'title' => ''];
        }

        if (! $reviewed) {
            return ['code' => '?', 'tone' => 'unreviewed', 'title' => __('Day not reviewed yet')];
        }

        return ['code' => '–', 'tone' => 'off', 'title' => __('No duty')];
    }
}
