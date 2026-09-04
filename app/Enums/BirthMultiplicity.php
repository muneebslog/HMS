<?php

namespace App\Enums;

enum BirthMultiplicity: string
{
    case Single = 'single';
    case Twin = 'twin';
    case Triplet = 'triplet';

    /**
     * Get the translated label for the birth multiplicity.
     */
    public function label(): string
    {
        return match ($this) {
            self::Single => __('Single'),
            self::Twin => __('Twin'),
            self::Triplet => __('Triplet'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $multiplicity) => $multiplicity->value, self::cases());
    }
}
