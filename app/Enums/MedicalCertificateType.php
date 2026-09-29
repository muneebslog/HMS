<?php

namespace App\Enums;

enum MedicalCertificateType: string
{
    case SickLeave = 'sick_leave';
    case Fitness = 'fitness';
    case Maternity = 'maternity';
    case Attendance = 'attendance';

    /**
     * Get the translated label for the certificate type.
     */
    public function label(): string
    {
        return match ($this) {
            self::SickLeave => __('Sick Leave'),
            self::Fitness => __('Fitness'),
            self::Maternity => __('Pregnancy / Maternity'),
            self::Attendance => __('Attendance'),
        };
    }

    /**
     * Get the heading printed on the certificate.
     */
    public function title(): string
    {
        return match ($this) {
            self::SickLeave => __('Medical Certificate'),
            self::Fitness => __('Medical Fitness Certificate'),
            self::Maternity => __('Pregnancy / Maternity Leave Certificate'),
            self::Attendance => __('Medical Attendance Certificate'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
