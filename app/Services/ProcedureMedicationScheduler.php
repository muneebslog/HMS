<?php

namespace App\Services;

use App\Enums\ProcedureMedicationDoseStatus;
use App\Enums\ProcedureMedicationScheduleType;
use App\Models\ProcedureMedication;
use App\Models\ProcedureMedicationDose;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProcedureMedicationScheduler
{
    /**
     * Create dose rows for a newly prescribed medication.
     *
     * @param  list<string>|null  $scheduleTimes  HH:MM or datetime strings
     */
    public function materialize(
        ProcedureMedication $medication,
        ?CarbonInterface $now = null,
        int $everyHourHorizon = 12
    ): Collection {
        $now ??= now();
        $dueAts = $this->resolveDueAts($medication, $now, $everyHourHorizon);

        return collect($dueAts)->map(function (CarbonInterface $dueAt) use ($medication): ProcedureMedicationDose {
            return $medication->doses()->firstOrCreate(
                ['due_at' => $dueAt],
                ['status' => ProcedureMedicationDoseStatus::Pending]
            );
        });
    }

    /**
     * Extend every-hour schedules for admitted patients.
     */
    public function extendHourlyDoses(ProcedureMedication $medication, int $hoursAhead = 6): Collection
    {
        if ($medication->schedule_type !== ProcedureMedicationScheduleType::EveryHour) {
            return collect();
        }

        $interval = max(1, (int) ($medication->interval_hours ?: 1));
        $start = $medication->starts_at?->copy() ?? now()->startOfHour();
        $horizon = now()->addHours($hoursAhead);
        $end = $medication->ends_at !== null ? $medication->ends_at->min($horizon) : $horizon;

        $dueAts = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addHours($interval)) {
            if ($cursor->gte(now()->subHour())) {
                $dueAts[] = $cursor->copy();
            }
        }

        return collect($dueAts)->map(function (CarbonInterface $dueAt) use ($medication): ProcedureMedicationDose {
            return $medication->doses()->firstOrCreate(
                ['due_at' => $dueAt],
                ['status' => ProcedureMedicationDoseStatus::Pending]
            );
        });
    }

    /**
     * @return list<CarbonInterface>
     */
    private function resolveDueAts(ProcedureMedication $medication, CarbonInterface $now, int $everyHourHorizon): array
    {
        $times = collect($medication->schedule_times ?? [])
            ->filter()
            ->map(fn (string $value) => $this->parseScheduleTime($value, $now))
            ->filter()
            ->values();

        return match ($medication->schedule_type) {
            ProcedureMedicationScheduleType::OnceNow => [$now->copy()],
            ProcedureMedicationScheduleType::OnceAt => [
                $times->first() ?? ($medication->starts_at?->copy() ?? $now->copy()),
            ],
            ProcedureMedicationScheduleType::NowAndAt => collect([$now->copy()])
                ->merge($times)
                ->unique(fn (CarbonInterface $time) => $time->format('Y-m-d H:i'))
                ->values()
                ->all(),
            ProcedureMedicationScheduleType::AtTimes => $times->all(),
            ProcedureMedicationScheduleType::EveryHour => $this->hourlyWindow(
                $medication->starts_at?->copy() ?? $now->copy(),
                $medication->ends_at,
                max(1, (int) ($medication->interval_hours ?: 1)),
                $everyHourHorizon
            ),
        };
    }

    /**
     * @return list<CarbonInterface>
     */
    private function hourlyWindow(CarbonInterface $start, ?CarbonInterface $endsAt, int $intervalHours, int $horizonHours): array
    {
        $horizon = $start->copy()->addHours($horizonHours);
        $end = $endsAt !== null ? $endsAt->min($horizon) : $horizon;
        $dueAts = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addHours($intervalHours)) {
            $dueAts[] = $cursor->copy();
        }

        return $dueAts;
    }

    private function parseScheduleTime(string $value, CarbonInterface $now): ?CarbonInterface
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}:\d{2}$/', $value) === 1) {
            [$hour, $minute] = array_map('intval', explode(':', $value));

            $candidate = $now->copy()->setTime($hour, $minute, 0);

            if ($candidate->lt($now->copy()->subMinutes(5))) {
                $candidate->addDay();
            }

            return $candidate;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
