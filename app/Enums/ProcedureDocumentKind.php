<?php

namespace App\Enums;

enum ProcedureDocumentKind: string
{
    case DischargeCertificate = 'discharge_certificate';
    case BirthCertificate = 'birth_certificate';
    case Bill = 'bill';

    /**
     * Get the translated label for the document kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::DischargeCertificate => __('Discharge Certificate'),
            self::BirthCertificate => __('Birth Certificate'),
            self::Bill => __('Bill'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind) => $kind->value, self::cases());
    }
}
