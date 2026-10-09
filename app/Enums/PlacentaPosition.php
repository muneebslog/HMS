<?php

namespace App\Enums;

enum PlacentaPosition: string
{
    case Anterior = 'anterior';
    case Posterior = 'posterior';
    case Fundal = 'fundal';
    case Lateral = 'lateral';
    case LowLying = 'low_lying';
    case Previa = 'previa';

    /**
     * Get the translated label for the placenta position.
     */
    public function label(): string
    {
        return match ($this) {
            self::Anterior => __('Anterior'),
            self::Posterior => __('Posterior'),
            self::Fundal => __('Fundal'),
            self::Lateral => __('Lateral'),
            self::LowLying => __('Low lying'),
            self::Previa => __('Previa'),
        };
    }

    /**
     * Whether this position needs the doctor's attention.
     */
    public function isConcerning(): bool
    {
        return in_array($this, [self::LowLying, self::Previa], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $position) => $position->value, self::cases());
    }
}
