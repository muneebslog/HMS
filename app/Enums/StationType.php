<?php

namespace App\Enums;

enum StationType: string
{
    case Er = 'er';
    case Drip = 'drip';

    /**
     * Get the translated label for the station type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Er => __('ER Station'),
            self::Drip => __('Drip Station'),
        };
    }
}
