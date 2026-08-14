<?php

namespace App\Enums;

enum TokenDisplayLayout: string
{
    case Board = 'board';
    case SingleToken = 'single_token';

    /**
     * Get the translated label for the display layout.
     */
    public function label(): string
    {
        return match ($this) {
            self::Board => __('Current board'),
            self::SingleToken => __('Single token'),
        };
    }
}
