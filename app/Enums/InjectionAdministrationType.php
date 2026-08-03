<?php

namespace App\Enums;

enum InjectionAdministrationType: string
{
    case Im = 'im';
    case Iv = 'iv';

    /**
     * Get the display label for the administration type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Im => __('IM'),
            self::Iv => __('IV'),
        };
    }
}
