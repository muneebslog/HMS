<?php

namespace App\Enums;

enum LabResultsStatus: string
{
    case Unknown = 'unknown';
    case Pending = 'pending';
    case Partial = 'partial';
    case Ready = 'ready';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unknown => __('Unknown'),
            self::Pending => __('Pending'),
            self::Partial => __('Partial'),
            self::Ready => __('Ready'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
