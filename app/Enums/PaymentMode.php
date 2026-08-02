<?php

namespace App\Enums;

enum PaymentMode: string
{
    case Cash = 'cash';
    case Online = 'online';

    /**
     * Get the translated label for the payment mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::Online => __('Online'),
        };
    }

    /**
     * Get all payment mode values as a list.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $mode) => $mode->value, self::cases());
    }
}
