<?php

namespace App\Enums;

enum LivingStatus: string
{
    case Living = 'living';
    case Deceased = 'deceased';

    /**
     * Get the translated label for the living status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Living => __('Living'),
            self::Deceased => __('Deceased'),
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
