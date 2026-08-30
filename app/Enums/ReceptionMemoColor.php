<?php

namespace App\Enums;

enum ReceptionMemoColor: string
{
    case Amber = 'amber';
    case Sky = 'sky';
    case Rose = 'rose';
    case Lime = 'lime';
    case Violet = 'violet';
    case Orange = 'orange';
    case Emerald = 'emerald';
    case Fuchsia = 'fuchsia';

    /**
     * Get the translated label for the color.
     */
    public function label(): string
    {
        return match ($this) {
            self::Amber => __('Amber'),
            self::Sky => __('Sky'),
            self::Rose => __('Rose'),
            self::Lime => __('Lime'),
            self::Violet => __('Violet'),
            self::Orange => __('Orange'),
            self::Emerald => __('Emerald'),
            self::Fuchsia => __('Fuchsia'),
        };
    }

    /**
     * Get the card surface classes for this color.
     */
    public function cardClasses(): string
    {
        return 'memo-card-'.$this->value;
    }

    /**
     * Get the divider classes used inside memo cards.
     */
    public function dividerClasses(): string
    {
        return 'memo-divider-'.$this->value;
    }

    /**
     * Get the icon accent classes for this color.
     */
    public function iconClasses(): string
    {
        return 'memo-icon-'.$this->value;
    }

    /**
     * Get the swatch classes for the color picker.
     */
    public function swatchClasses(): string
    {
        return 'memo-swatch-'.$this->value;
    }

    /**
     * Get the form panel classes for the create memo modal.
     */
    public function formPanelClasses(): string
    {
        return 'memo-form-'.$this->value;
    }

    /**
     * Get all color values as a list.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $color) => $color->value, self::cases());
    }
}
