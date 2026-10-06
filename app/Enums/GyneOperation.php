<?php

namespace App\Enums;

enum GyneOperation: string
{
    case CSection = 'c_section';
    case DilationAndCurettage = 'dilation_and_curettage';
    case Ectopic = 'ectopic';
    case OvarianCyst = 'ovarian_cyst';
    case Fibroids = 'fibroids';
    case Hysterectomy = 'hysterectomy';
    case TubalLigation = 'tubal_ligation';
    case Appendectomy = 'appendectomy';

    /**
     * Get the translated label for the operation.
     */
    public function label(): string
    {
        return match ($this) {
            self::CSection => __('C-section'),
            self::DilationAndCurettage => __('D&C'),
            self::Ectopic => __('Ectopic'),
            self::OvarianCyst => __('Ovarian cyst'),
            self::Fibroids => __('Fibroids'),
            self::Hysterectomy => __('Hysterectomy'),
            self::TubalLigation => __('Tubal ligation'),
            self::Appendectomy => __('Appendectomy'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $operation) => $operation->value, self::cases());
    }
}
