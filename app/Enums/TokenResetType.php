<?php

namespace App\Enums;

enum TokenResetType: string
{
    case Shift = 'shift';
    case Daily = 'daily';

    /**
     * Get the translated label for the reset type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Shift => __('Shift'),
            self::Daily => __('Daily'),
        };
    }
}
