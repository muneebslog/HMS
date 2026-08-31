<?php

namespace App\Enums;

enum StockLocation: string
{
    case BackStorage = 'back_storage';
    case FrontWorking = 'front_working';

    /**
     * Get the translated label for the stock location.
     */
    public function label(): string
    {
        return match ($this) {
            self::BackStorage => __('Back Storage'),
            self::FrontWorking => __('Front Working'),
        };
    }
}
