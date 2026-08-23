<?php

namespace App\Enums;

enum WorkSessionStatus: string
{
    case Open = 'open';
    case Suggested = 'suggested';
    case Confirmed = 'confirmed';

    /**
     * Get the translated label for the work session status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Suggested => __('Suggested'),
            self::Confirmed => __('Confirmed'),
        };
    }

    /**
     * Get a Flux badge color variant for the work session status.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Open => 'amber',
            self::Suggested => 'zinc',
            self::Confirmed => 'green',
        };
    }
}
