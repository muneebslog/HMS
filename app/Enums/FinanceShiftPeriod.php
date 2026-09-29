<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum FinanceShiftPeriod: string
{
    case Morning = 'morning';
    case Evening = 'evening';
    case Night = 'night';

    /**
     * Get the translated label for the period.
     */
    public function label(): string
    {
        return match ($this) {
            self::Morning => __('Morning'),
            self::Evening => __('Evening'),
            self::Night => __('Night'),
        };
    }

    /**
     * Hour (0-23) at which a business day starts: shifts opened from this hour belong to the next day's night.
     */
    public const BusinessDayStartHour = 18;

    /**
     * Determine the period a shift belongs to from the time it was opened.
     */
    public static function forOpenedAt(CarbonInterface $openedAt): self
    {
        return match (true) {
            $openedAt->hour >= self::BusinessDayStartHour, $openedAt->hour < 5 => self::Night,
            $openedAt->hour < 12 => self::Morning,
            default => self::Evening,
        };
    }

    /**
     * Determine the business date a shift belongs to (a night opened in the evening counts toward the next day).
     */
    public static function businessDateFor(CarbonInterface $openedAt): CarbonImmutable
    {
        $date = CarbonImmutable::parse($openedAt)->startOfDay();

        return $openedAt->hour >= self::BusinessDayStartHour ? $date->addDay() : $date;
    }

    /**
     * Get the order periods are shown in within a business day.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Night => 0,
            self::Morning => 1,
            self::Evening => 2,
        };
    }

    /**
     * Get all period values as a list.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $period) => $period->value, self::cases());
    }
}
