<?php

namespace App\Enums;

enum EmergencyDepartmentShift: string
{
    case Morning = 'morning';
    case Evening = 'evening';
    case Night = 'night';

    /**
     * Get the translated label for the shift.
     */
    public function label(): string
    {
        return match ($this) {
            self::Morning => __('Morning'),
            self::Evening => __('Evening'),
            self::Night => __('Night'),
        };
    }
}
