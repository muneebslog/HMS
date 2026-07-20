<?php

namespace App\Enums;

enum LabApiStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Sent => __('Sent'),
            self::Failed => __('Failed'),
            self::Skipped => __('Skipped'),
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
