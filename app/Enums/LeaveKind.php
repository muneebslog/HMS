<?php

namespace App\Enums;

enum LeaveKind: string
{
    case Informed = 'informed';
    case Uninformed = 'uninformed';

    /**
     * Get the translated label for the leave kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Informed => __('Informed leave'),
            self::Uninformed => __('Uninformed absence'),
        };
    }

    /**
     * Badge color used when showing the kind.
     */
    public function color(): string
    {
        return match ($this) {
            self::Informed => 'zinc',
            self::Uninformed => 'red',
        };
    }
}
