<?php

namespace App\Enums;

enum ProcedureMedicationScheduleType: string
{
    case OnceNow = 'once_now';
    case OnceAt = 'once_at';
    case EveryHour = 'every_hour';
    case NowAndAt = 'now_and_at';
    case AtTimes = 'at_times';

    /**
     * Get the translated label for the schedule type.
     */
    public function label(): string
    {
        return match ($this) {
            self::OnceNow => __('Once now'),
            self::OnceAt => __('Once at time'),
            self::EveryHour => __('Every hour'),
            self::NowAndAt => __('Now and at time(s)'),
            self::AtTimes => __('At scheduled time(s)'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
