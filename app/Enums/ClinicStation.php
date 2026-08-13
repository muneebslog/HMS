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
}
