<?php

namespace App\Enums;

enum AttendanceRecordStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case EarlyLeave = 'early_leave';
    case Absent = 'absent';
    case OnLeave = 'on_leave';
    case Incomplete = 'incomplete';
    case ExtraOnly = 'extra_only';

    /**
     * Get the translated label for the record status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Present => __('Present'),
            self::Late => __('Late'),
            self::EarlyLeave => __('Early Leave'),
            self::Absent => __('Absent'),
            self::OnLeave => __('On Leave'),
            self::Incomplete => __('Incomplete'),
            self::ExtraOnly => __('Extra Only'),
        };
    }

    /**
     * Get a Flux badge color variant for the status.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Present => 'green',
            self::Late => 'amber',
            self::EarlyLeave => 'amber',
            self::Absent => 'red',
            self::OnLeave => 'zinc',
            self::Incomplete => 'orange',
            self::ExtraOnly => 'blue',
        };
    }
}
