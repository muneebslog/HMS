<?php

namespace App\Enums;

enum PolicyJournalStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Active => __('Active'),
            self::Archived => __('Archived'),
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

    /**
     * Get the statuses in display order.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Active,
            self::Draft,
            self::Archived,
        ];
    }
}
