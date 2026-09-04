<?php

namespace App\Enums;

enum ProcedureStatus: string
{
    case Booking = 'booking';
    case Admitted = 'admitted';
    case Discharged = 'discharged';

    /**
     * Get the translated label for the procedure status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Booking => __('Booking'),
            self::Admitted => __('Admitted'),
            self::Discharged => __('Discharged'),
        };
    }

    /**
     * Get a Flux badge color variant for the procedure status.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Booking => 'zinc',
            self::Admitted => 'blue',
            self::Discharged => 'green',
        };
    }
}
