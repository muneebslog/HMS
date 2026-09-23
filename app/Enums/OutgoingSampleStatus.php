<?php

namespace App\Enums;

enum OutgoingSampleStatus: string
{
    case Pending = 'pending';
    case Asked = 'asked';
    case Given = 'given';
    case Received = 'received';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Rider not called'),
            self::Asked => __('Rider called'),
            self::Given => __('Handed to rider'),
            self::Received => __('Result received'),
        };
    }

    /**
     * Get the next status in the forward-only workflow, if any.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Pending => self::Asked,
            self::Asked => self::Given,
            self::Given => self::Received,
            self::Received => null,
        };
    }

    /**
     * Determine whether this status can advance to the given status.
     */
    public function canAdvanceTo(self $target): bool
    {
        return $this->next() === $target;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
