<?php

namespace App\Enums;

enum MedicationOrderStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Administered = 'administered';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Recalled'),
            self::Pending => __('Pending'),
            self::Administered => __('Administered'),
        };
    }
}
