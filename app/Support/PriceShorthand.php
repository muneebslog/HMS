<?php

namespace App\Support;

/**
 * Reads the price codes reception and doctors type instead of full amounts.
 *
 * "z" is a hundred and "y" is fifty. A number in front of a letter multiplies it,
 * and the parts are added up: 12z = 1200, yz = zy = 150, 12zy = 1250, 12z50 = 1250.
 * Plain numbers such as 1200 or 1200.50 pass through unchanged.
 */
class PriceShorthand
{
    private const UNITS = [
        'z' => 100,
        'y' => 50,
    ];

    /**
     * Convert a typed price or price code to a number, or null when it is not a valid price.
     */
    public static function parse(?string $input): ?float
    {
        $value = strtolower(str_replace([',', ' '], '', trim((string) $input)));

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (preg_match('/^(?:\d*[zy])+\d*$/', $value) !== 1) {
            return null;
        }

        preg_match_all('/(\d*)([zy])|(\d+)$/', $value, $parts, PREG_SET_ORDER);

        $total = 0;

        foreach ($parts as $part) {
            if (isset($part[3]) && $part[3] !== '') {
                $total += (int) $part[3];

                continue;
            }

            $multiplier = $part[1] === '' ? 1 : (int) $part[1];
            $total += $multiplier * self::UNITS[$part[2]];
        }

        return (float) $total;
    }
}
