<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Receptionist = 'receptionist';
    case Management = 'management';
    case Doctor = 'doctor';
    case User = 'user';

    /**
     * Get the translated label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Receptionist => __('Receptionist'),
            self::Management => __('Management'),
            self::Doctor => __('Doctor'),
            self::User => __('User'),
        };
    }

    /**
     * Get all role values as a list.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
