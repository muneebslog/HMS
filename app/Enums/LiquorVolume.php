<?php

namespace App\Enums;

enum LiquorVolume: string
{
    case Adequate = 'adequate';
    case Reduced = 'reduced';
    case Increased = 'increased';

    /**
     * Get the translated label for the liquor volume.
     */
    public function label(): string
    {
        return match ($this) {
            self::Adequate => __('Adequate'),
            self::Reduced => __('Reduced'),
            self::Increased => __('Increased'),
        };
    }

    /**
     * Whether this volume needs the doctor's attention.
     */
    public function isConcerning(): bool
    {
        return $this !== self::Adequate;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $volume) => $volume->value, self::cases());
    }
}
