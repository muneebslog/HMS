<?php

namespace App\Enums;

enum WardMaintenanceFaultPriority: string
{
    case Urgent = 'urgent';
    case Routine = 'routine';

    /**
     * Get the translated label for the priority.
     */
    public function label(): string
    {
        return match ($this) {
            self::Urgent => __('Urgent'),
            self::Routine => __('Routine'),
        };
    }
}
