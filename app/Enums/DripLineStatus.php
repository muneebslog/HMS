<?php

namespace App\Enums;

enum DripLineStatus: string
{
    case Pending = 'pending';
    case Started = 'started';
    case Done = 'done';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Started => __('Started'),
            self::Done => __('Done'),
        };
    }
}
