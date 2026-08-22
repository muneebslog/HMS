<?php

namespace App\Services;

use App\Enums\AttendanceRecordStatus;
use App\Models\AttendanceRecord;
use App\Models\HealthAide;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PayrollReportService
{
    /**
     * Build a monthly payroll summary for health aides.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function monthlySummary(int $year, int $month, ?string $station = null): Collection
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $recordsQuery = AttendanceRecord::query()
            ->with(['healthAide', 'dutyAssignment'])
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()]);

        if ($station !== null && $station !== '') {
            $recordsQuery->whereHas('dutyAssignment', fn ($query) => $query->where('station', $station));
        }

        $records = $recordsQuery->get()->groupBy('health_aide_id');

        return $records->map(function (Collection $aideRecords, int $healthAideId) {
            /** @var HealthAide $healthAide */
            $healthAide = $aideRecords->first()->healthAide;

            $regularMinutes = $aideRecords->sum(fn (AttendanceRecord $record) => max(0, $record->payable_minutes - $record->overtime_minutes));
            $overtimeMinutes = $aideRecords->sum('overtime_minutes');
            $payableMinutes = $aideRecords->sum('payable_minutes');

            return [
                'health_aide_id' => $healthAideId,
                'health_aide_name' => $healthAide->name,
                'days_worked' => $aideRecords->filter(fn (AttendanceRecord $record) => $record->payable_minutes > 0)->count(),
                'regular_hours' => round($regularMinutes / 60, 2),
                'overtime_hours' => round($overtimeMinutes / 60, 2),
                'payable_hours' => round($payableMinutes / 60, 2),
                'late_count' => $aideRecords->where('late_minutes', '>', 0)->count(),
                'absent_count' => $aideRecords->where('status', AttendanceRecordStatus::Absent)->count(),
            ];
        })->sortBy('health_aide_name')->values();
    }

    /**
     * Export monthly payroll summary as CSV rows.
     *
     * @return list<list<string>>
     */
    public function monthlySummaryCsv(int $year, int $month, ?string $station = null): array
    {
        $rows = [[
            'Health Aide',
            'Days Worked',
            'Regular Hours',
            'Overtime Hours',
            'Payable Hours',
            'Late Count',
            'Absent Count',
        ]];

        foreach ($this->monthlySummary($year, $month, $station) as $row) {
            $rows[] = [
                $row['health_aide_name'],
                (string) $row['days_worked'],
                (string) $row['regular_hours'],
                (string) $row['overtime_hours'],
                (string) $row['payable_hours'],
                (string) $row['late_count'],
                (string) $row['absent_count'],
            ];
        }

        return $rows;
    }
}
