<?php

namespace App\Enums;

enum LabFieldRangeCategory: string
{
    case General = 'general';
    case Male = 'male';
    case Female = 'female';
    case Child = 'child';

    /**
     * Get the translated label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::General => __('General'),
            self::Male => __('Male'),
            self::Female => __('Female'),
            self::Child => __('Child'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $category) => $category->value, self::cases());
    }
}
