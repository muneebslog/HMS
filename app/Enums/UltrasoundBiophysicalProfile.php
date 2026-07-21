<?php

namespace App\Enums;

enum UltrasoundBiophysicalProfile: string
{
    case Poor = 'poor';
    case Normal = 'normal';
    case Good = 'good';

    /**
     * Get the translated label for the profile.
     */
    public function label(): string
    {
        return match ($this) {
            self::Poor => __('Poor'),
            self::Normal => __('Normal'),
            self::Good => __('Good'),
        };
    }

    /**
     * Get all profile values as a list.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $profile) => $profile->value, self::cases());
    }
}
