<?php

namespace App\Enums;

enum ReceptionMemoColor: string
{
    case Amber = 'amber';
    case Sky = 'sky';
    case Rose = 'rose';
    case Lime = 'lime';
    case Violet = 'violet';

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
        };
    }

    /**
     * Get the card surface classes for this color.
     */
    public function cardClasses(): string
    {
        return match ($this) {
            self::Amber => 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/40',
            self::Sky => 'border-sky-300 bg-sky-50 dark:border-sky-700 dark:bg-sky-950/40',
            self::Rose => 'border-rose-300 bg-rose-50 dark:border-rose-700 dark:bg-rose-950/40',
            self::Lime => 'border-lime-300 bg-lime-50 dark:border-lime-700 dark:bg-lime-950/40',
            self::Violet => 'border-violet-300 bg-violet-50 dark:border-violet-700 dark:bg-violet-950/40',
        };
    }

    /**
     * Get the divider classes used inside memo cards.
     */
    public function dividerClasses(): string
    {
        return match ($this) {
            self::Amber => 'border-amber-200 dark:border-amber-800',
            self::Sky => 'border-sky-200 dark:border-sky-800',
            self::Rose => 'border-rose-200 dark:border-rose-800',
            self::Lime => 'border-lime-200 dark:border-lime-800',
            self::Violet => 'border-violet-200 dark:border-violet-800',
        };
    }

    /**
     * Get the icon accent classes for this color.
     */
    public function iconClasses(): string
    {
        return match ($this) {
            self::Amber => 'text-amber-600 dark:text-amber-400',
            self::Sky => 'text-sky-600 dark:text-sky-400',
            self::Rose => 'text-rose-600 dark:text-rose-400',
            self::Lime => 'text-lime-600 dark:text-lime-400',
            self::Violet => 'text-violet-600 dark:text-violet-400',
        };
    }

    /**
     * Get the swatch classes for the color picker.
     */
    public function swatchClasses(): string
    {
        return match ($this) {
            self::Amber => 'bg-amber-400 ring-amber-500',
            self::Sky => 'bg-sky-400 ring-sky-500',
            self::Rose => 'bg-rose-400 ring-rose-500',
            self::Lime => 'bg-lime-400 ring-lime-500',
            self::Violet => 'bg-violet-400 ring-violet-500',
        };
    }

    /**
     * Get the form panel classes for the create memo modal.
     */
    public function formPanelClasses(): string
    {
        return match ($this) {
            self::Amber => 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30',
            self::Sky => 'border-sky-200 bg-sky-50 dark:border-sky-800 dark:bg-sky-950/30',
            self::Rose => 'border-rose-200 bg-rose-50 dark:border-rose-800 dark:bg-rose-950/30',
            self::Lime => 'border-lime-200 bg-lime-50 dark:border-lime-800 dark:bg-lime-950/30',
            self::Violet => 'border-violet-200 bg-violet-50 dark:border-violet-800 dark:bg-violet-950/30',
        };
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
