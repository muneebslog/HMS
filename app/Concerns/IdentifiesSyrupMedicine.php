<?php

namespace App\Concerns;

trait IdentifiesSyrupMedicine
{
    /**
     * Determine whether the medicine name represents a syrup.
     */
    public function isSyrup(): bool
    {
        return self::nameIsSyrup($this->name ?? '');
    }

    /**
     * Determine whether the given medicine name represents a syrup.
     */
    public static function nameIsSyrup(?string $name): bool
    {
        return str_starts_with(strtolower(ltrim($name ?? '')), 'syp.');
    }
}
