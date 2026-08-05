<?php

namespace App\Enums;

enum ProcedureNoteStyle: string
{
    case Operation = 'operation';
    case Delivery = 'delivery';

    /**
     * Get the translated label for the note style.
     */
    public function label(): string
    {
        return match ($this) {
            self::Operation => __('Operation notes'),
            self::Delivery => __('Delivery notes'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $style) => $style->value, self::cases());
    }
}
