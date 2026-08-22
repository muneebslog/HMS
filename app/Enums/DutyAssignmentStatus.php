<?php

namespace App\Enums;

enum DutyAssignmentStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';

    /**
     * Get the translated label for the assignment status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
