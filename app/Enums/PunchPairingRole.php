<?php

namespace App\Enums;

enum PunchPairingRole: string
{
    case In = 'in';
    case Out = 'out';
    case Ignore = 'ignore';

    /**
     * Get the translated label for the pairing role.
     */
    public function label(): string
    {
        return match ($this) {
            self::In => __('In'),
            self::Out => __('Out'),
            self::Ignore => __('Ignore'),
        };
    }

    /**
     * Get a Flux badge color variant for the pairing role.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::In => 'green',
            self::Out => 'blue',
            self::Ignore => 'zinc',
        };
    }
}
