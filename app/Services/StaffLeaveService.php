<?php

namespace App\Services;

use App\Enums\LeaveKind;
use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\StaffLeave;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records days off and works out which of them are paid.
 *
 * Informed leaves are paid in date order until the monthly allowance runs
 * out; the rest of that month's informed leave is deducted. Uninformed
 * absences are always deducted and never use the allowance.
 */
class StaffLeaveService
{
    /**
     * Record leave for every day from $from to $to (holidays are skipped).
     *
     * @return Collection<int, StaffLeave>
     */
    public function record(
        Employee $employee,
        CarbonImmutable $from,
        CarbonImmutable $to,
        LeaveKind $kind,
        bool $halfDay,
        ?string $reason,
        bool $forcePaid,
        User $user,
    ): Collection {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => __('The last day must be on or after the first day.')]);
        }

        if ($from->diffInDays($to) > 31) {
            throw ValidationException::withMessages(['to' => __('Record at most one month at a time.')]);
        }

        if ($halfDay && ! $from->equalTo($to)) {
            throw ValidationException::withMessages(['to' => __('A half day covers a single date.')]);
        }

        $holidays = Holiday::query()
            ->whereDate('holiday_date', '>=', $from)
            ->whereDate('holiday_date', '<=', $to)
            ->pluck('holiday_date')
            ->map(fn ($date): string => $date->toDateString());

        $dates = collect(range(0, (int) $from->diffInDays($to)))
            ->map(fn (int $offset): CarbonImmutable => $from->addDays($offset))
            ->reject(fn (CarbonImmutable $date): bool => $holidays->contains($date->toDateString()))
            ->values();

        if ($dates->isEmpty()) {
            throw ValidationException::withMessages(['from' => __('Those dates are all public holidays.')]);
        }

        $taken = $employee->leaves()
            ->whereDate('leave_date', '>=', $from)
            ->whereDate('leave_date', '<=', $to)
            ->pluck('leave_date')
            ->filter(fn ($date): bool => $dates->contains(fn (CarbonImmutable $candidate): bool => $candidate->isSameDay($date)))
            ->map(fn ($date): string => $date->format('D j M'));

        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages(['from' => __(':name already has leave on :dates.', ['name' => $employee->name, 'dates' => $taken->join(', ')])]);
        }

        if (! $halfDay) {
            $worked = $employee->dutySessions()
                ->whereDate('duty_date', '>=', $from)
                ->whereDate('duty_date', '<=', $to)
                ->pluck('duty_date')
                ->filter(fn ($date): bool => $dates->contains(fn (CarbonImmutable $candidate): bool => $candidate->isSameDay($date)))
                ->map(fn ($date): string => $date->format('D j M'));

            if ($worked->isNotEmpty()) {
                throw ValidationException::withMessages(['from' => __(':name has a confirmed duty on :dates.', ['name' => $employee->name, 'dates' => $worked->join(', ')])]);
            }
        }

        return DB::transaction(function () use ($employee, $dates, $kind, $halfDay, $reason, $forcePaid, $user): Collection {
            $leaves = $dates->map(fn (CarbonImmutable $date): StaffLeave => $employee->leaves()->create([
                'leave_date' => $date->toDateString(),
                'kind' => $kind,
                'days' => $halfDay ? 0.5 : 1,
                'paid_days' => 0,
                'force_paid' => $kind === LeaveKind::Informed && $forcePaid,
                'reason' => $reason,
                'created_by' => $user->id,
            ]));

            $dates->map(fn (CarbonImmutable $date): string => $date->format('Y-m'))
                ->unique()
                ->each(fn (string $month) => $this->recalculateMonth($employee, CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')));

            return $leaves->map->fresh();
        });
    }

    /**
     * Remove a leave and re-spread the allowance over the rest of the month.
     */
    public function delete(StaffLeave $leave): void
    {
        DB::transaction(function () use ($leave): void {
            $leave->delete();
            $this->recalculateMonth($leave->employee, CarbonImmutable::parse($leave->leave_date));
        });
    }

    /**
     * Re-apply the paid allowance to one person's leaves in one month.
     */
    public function recalculateMonth(Employee $employee, CarbonImmutable $month): void
    {
        $allowanceLeft = (float) AppSetting::attendanceRule(AppSetting::PaidLeavesPerMonth);

        $leaves = $employee->leaves()
            ->whereDate('leave_date', '>=', $month->startOfMonth())
            ->whereDate('leave_date', '<=', $month->endOfMonth())
            ->orderBy('leave_date')
            ->orderBy('id')
            ->get();

        foreach ($leaves as $leave) {
            $paid = match (true) {
                $leave->kind === LeaveKind::Uninformed => 0.0,
                $leave->force_paid => $leave->days,
                default => min($leave->days, max(0.0, $allowanceLeft)),
            };

            if ($leave->kind === LeaveKind::Informed) {
                $allowanceLeft -= $paid;
            }

            if ($leave->paid_days !== $paid) {
                $leave->update(['paid_days' => $paid]);
            }
        }
    }

    /**
     * Paid leave days still available to a person in the month of $date.
     */
    public function allowanceLeft(Employee $employee, CarbonImmutable $date): float
    {
        $used = (float) $employee->leaves()
            ->where('kind', LeaveKind::Informed)
            ->whereDate('leave_date', '>=', $date->startOfMonth())
            ->whereDate('leave_date', '<=', $date->endOfMonth())
            ->sum('paid_days');

        return max(0.0, AppSetting::attendanceRule(AppSetting::PaidLeavesPerMonth) - $used);
    }
}
