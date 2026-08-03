<?php

namespace App\Enums;

enum MedicineDose: string
{
    case OneZeroZero = '1-0-0';
    case OneZeroOne = '1-0-1';
    case OneOneOne = '1-1-1';

    /**
     * Get the display label for the dose pattern.
     */
    public function label(): string
    {
        return $this->value;
    }
}
