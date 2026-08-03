<?php

namespace App\Enums;

enum MedicationOrderStatus: string
{
    case Pending = 'pending';
    case Administered = 'administered';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Administered => __('Administered'),
        };
    }
}
