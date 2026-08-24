<?php

namespace App\Enums;

enum EmergencyDepartmentEquipmentStatus: string
{
    case Ok = 'ok';
    case Issue = 'issue';

    /**
     * Get the translated label for the equipment status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ok => __('OK'),
            self::Issue => __('Issue'),
        };
    }
}
