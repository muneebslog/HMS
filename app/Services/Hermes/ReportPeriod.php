<?php

namespace App\Services\Hermes;

use App\Enums\FinanceShiftPeriod;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The window a Hermes report covers: one shift, or one business day (the night opened at 18:00
 * the evening before, then that day's morning and evening).
 */
final readonly class ReportPeriod
{
    /**
     * @param  Collection<int, Shift>  $shifts
     */
    private function __construct(
        public string $label,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public Collection $shifts,
    ) {}

    /**
     * Resolve the period from tool arguments: a shift id, "current" for the open shift, or a business date.
     *
     * @throws InvalidArgumentException
     */
    public static function resolve(?string $date = null, int|string|null $shiftId = null): self
    {
        if ($shiftId === 'current') {
            $shift = Shift::current() ?? throw new InvalidArgumentException('No shift is open right now.');

            return self::forShift($shift);
        }

        if (filled($shiftId)) {
            $shift = Shift::query()->find((int) $shiftId) ?? throw new InvalidArgumentException("Shift {$shiftId} was not found.");

            return self::forShift($shift);
        }

        return self::forBusinessDate(filled($date) ? self::parseDate($date) : FinanceShiftPeriod::businessDateFor(now()));
    }

    public static function forShift(Shift $shift): self
    {
        return new self(
            label: sprintf('Shift %d (%s, business day %s)', $shift->id, $shift->period()->label(), $shift->businessDate()->toDateString()),
            from: CarbonImmutable::parse($shift->opened_at),
            to: CarbonImmutable::parse($shift->closed_at ?? now()),
            shifts: collect([$shift]),
        );
    }

    /**
     * The window runs from the first shift opening to the last one closing (shifts often run past 18:00),
     * or 18:00 to 18:00 when the day has no shifts.
     */
    public static function forBusinessDate(CarbonImmutable $date): self
    {
        $dayStart = $date->startOfDay()->subDay()->setHour(FinanceShiftPeriod::BusinessDayStartHour);
        $shifts = Shift::query()->forBusinessDate($date)->with('user')->orderBy('opened_at')->get();
        $lastShift = $shifts->last();

        return new self(
            label: 'Business day '.$date->toDateString(),
            from: $shifts->isEmpty() ? $dayStart : CarbonImmutable::parse($shifts->first()->opened_at),
            to: $lastShift === null
                ? $dayStart->addDay()->min(CarbonImmutable::now())
                : CarbonImmutable::parse($lastShift->closed_at ?? now()),
            shifts: $shifts,
        );
    }

    /**
     * @return list<int>
     */
    public function shiftIds(): array
    {
        return $this->shifts->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * @return array{label: string, from: string, to: string, shift_ids: list<int>}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'from' => $this->from->toDateTimeString(),
            'to' => $this->to->toDateTimeString(),
            'shift_ids' => $this->shiftIds(),
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function parseDate(string $date): CarbonImmutable
    {
        $normalized = strtolower(trim($date));

        return match ($normalized) {
            'today' => FinanceShiftPeriod::businessDateFor(now()),
            'yesterday' => FinanceShiftPeriod::businessDateFor(now())->subDay(),
            default => preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1
                ? CarbonImmutable::parse($normalized)->startOfDay()
                : throw new InvalidArgumentException("Use a date like 2026-10-15, 'today' or 'yesterday' (got '{$date}')."),
        };
    }
}
