<?php

namespace App\Enums;

enum AdminReportStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Closed => __('Closed'),
        };
    }

    /**
     * Get all status values as a list.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
