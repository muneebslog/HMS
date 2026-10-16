<?php

namespace App\Enums;

enum StaffCategory: string
{
    case Doctor = 'doctor';
    case Medical = 'medical';
    case NonMedical = 'non_medical';
    case HealthAide = 'health_aide';

    /**
     * Get the translated label for the staff category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Doctor => __('Doctor'),
            self::Medical => __('Nurse / Medical'),
            self::NonMedical => __('Non-medical'),
            self::HealthAide => __('Health Aide'),
        };
    }

    /**
     * Badge color used when showing the category.
     */
    public function color(): string
    {
        return match ($this) {
            self::Doctor => 'indigo',
            self::Medical => 'sky',
            self::NonMedical => 'amber',
            self::HealthAide => 'emerald',
        };
    }

    /**
     * Whether staff in this category hold degrees (otherwise they list training).
     */
    public function holdsDegrees(): bool
    {
        return $this !== self::HealthAide;
    }

    /**
     * Whether staff in this category punch on the attendance machine by default.
     */
    public function tracksAttendanceByDefault(): bool
    {
        return $this !== self::Doctor;
    }
}
