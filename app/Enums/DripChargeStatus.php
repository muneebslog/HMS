<?php

namespace App\Enums;

enum DripChargeStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Paid => __('Paid'),
        };
    }
}
