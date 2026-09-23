<?php

namespace App\Enums;

enum LabFieldType: string
{
    case Numeric = 'numeric';
    case Text = 'text';
    case Choice = 'choice';

    /**
     * Get the translated label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Numeric => __('Numeric'),
            self::Text => __('Text'),
            self::Choice => __('Choice'),
        };
    }

    /**
     * Whether fields of this type can have normal ranges.
     */
    public function hasRanges(): bool
    {
        return $this === self::Numeric;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
