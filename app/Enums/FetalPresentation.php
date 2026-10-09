<?php

namespace App\Enums;

enum FetalPresentation: string
{
    case Cephalic = 'cephalic';
    case Breech = 'breech';
    case Transverse = 'transverse';
    case Oblique = 'oblique';
    case Variable = 'variable';

    /**
     * Get the translated label for the presentation.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cephalic => __('Cephalic'),
            self::Breech => __('Breech'),
            self::Transverse => __('Transverse'),
            self::Oblique => __('Oblique'),
            self::Variable => __('Variable'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $presentation) => $presentation->value, self::cases());
    }
}
