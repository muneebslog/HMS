<?php

namespace App\Enums;

enum WardMaintenanceStatus: string
{
    case Ok = 'ok';
    case Fault = 'fault';
    case NotApplicable = 'na';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ok => __('OK'),
            self::Fault => __('Fault'),
            self::NotApplicable => __('N/A'),
        };
    }
}
