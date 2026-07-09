<?php

namespace App\Enums;

enum SmsStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Queued'),
            self::Sent => __('Sent'),
            self::Failed => __('Failed'),
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
