<?php

namespace App\Enums;

enum ClinicStation: string
{
    case Vitals = 'vitals';
    case Doctor = 'doctor';
    case Reception = 'reception';
    case Drip = 'drip';
    case Er = 'er';
    case Done = 'done';

    /**
     * Get the translated label for the station.
     */
    public function label(): string
    {
        return match ($this) {
            self::Vitals => __('Vitals'),
            self::Doctor => __('Doctor'),
            self::Reception => __('Reception'),
            self::Drip => __('Drip'),
            self::Er => __('ER'),
            self::Done => __('Done'),
        };
    }

    /**
     * Short reason the patient is in this column.
     */
    public function waitingLabel(): string
    {
        return match ($this) {
            self::Vitals => __('Waiting for vitals'),
            self::Doctor => __('Waiting for doctor'),
            self::Reception => __('Not yet paid'),
            self::Drip => __('Drip not yet given'),
            self::Er => __('Ready for ER'),
            self::Done => __('Completed'),
        };
    }

    /**
     * Column shell classes for the patient flow board.
     */
    public function columnClasses(): string
    {
        return match ($this) {
            self::Vitals => 'border-sky-200 bg-sky-50/80 dark:border-sky-900 dark:bg-sky-950/30',
            self::Doctor => 'border-indigo-200 bg-indigo-50/80 dark:border-indigo-900 dark:bg-indigo-950/30',
            self::Reception => 'border-amber-200 bg-amber-50/80 dark:border-amber-900 dark:bg-amber-950/30',
            self::Drip => 'border-cyan-200 bg-cyan-50/80 dark:border-cyan-900 dark:bg-cyan-950/30',
            self::Er => 'border-rose-200 bg-rose-50/80 dark:border-rose-900 dark:bg-rose-950/30',
            self::Done => 'border-emerald-200 bg-emerald-50/80 dark:border-emerald-900 dark:bg-emerald-950/30',
        };
    }

    /**
     * Flux badge color for this station.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Vitals => 'sky',
            self::Doctor => 'indigo',
            self::Reception => 'amber',
            self::Drip => 'cyan',
            self::Er => 'rose',
            self::Done => 'green',
        };
    }
}
