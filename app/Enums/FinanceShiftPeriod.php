<?php

namespace App\Enums;

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
     * Get all period values as a list.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $period) => $period->value, self::cases());
    }
}
