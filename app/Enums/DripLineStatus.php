<?php

namespace App\Enums;

enum DripLineStatus: string
{
    case Pending = 'pending';
    case Started = 'started';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /**
     * Statuses that mean the drip line is not finished yet.
     *
     * @return list<self>
     */
    public static function activeCases(): array
    {
        return [self::Pending, self::Started];
    }

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Started => __('Started'),
            self::Done => __('Done'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
