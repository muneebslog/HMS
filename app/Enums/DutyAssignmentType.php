<?php

namespace App\Enums;

enum DutyAssignmentType: string
{
    case Regular = 'regular';
    case Extra = 'extra';
    case Emergency = 'emergency';
    case Replacement = 'replacement';

    /**
     * Get the translated label for the assignment type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Regular => __('Regular'),
            self::Extra => __('Extra'),
            self::Emergency => __('Emergency'),
            self::Replacement => __('Replacement'),
        };
    }
}
