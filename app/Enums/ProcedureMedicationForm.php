<?php

namespace App\Enums;

enum ProcedureMedicationForm: string
{
    case Tab = 'tab';
    case Inj = 'inj';
    case Drip = 'drip';

    /**
     * Get the translated label for the medication form.
     */
    public function label(): string
    {
        return match ($this) {
            self::Tab => __('Tablet'),
            self::Inj => __('Injection'),
            self::Drip => __('Drip'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $form) => $form->value, self::cases());
    }
}
