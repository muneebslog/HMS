<?php

namespace App\Enums;

enum ProcedureMedicationDoseStatus: string
{
    case Pending = 'pending';
    case Given = 'given';
    case Skipped = 'skipped';

    /**
     * Get the translated label for the dose status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Given => __('Given'),
            self::Skipped => __('Skipped'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
