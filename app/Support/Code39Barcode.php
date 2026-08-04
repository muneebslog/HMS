<?php

namespace App\Support;

class Code39Barcode
{
    /**
     * Code 39 patterns: 1 = bar, 0 = space. Narrow = 1 unit, wide = 3 units.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        '0' => '000110100',
        '1' => '100100001',
        '2' => '001100001',
        '3' => '101100000',
        '4' => '000110001',
        '5' => '100110000',
        '6' => '001110000',
        '7' => '000100101',
        '8' => '100100100',
        '9' => '001100100',
        'A' => '100001001',
        'B' => '001001001',
        'C' => '101001000',
        'D' => '000011001',
        'E' => '100011000',
        'F' => '001011000',
        'G' => '000001101',
        'H' => '100001100',
        'I' => '001001100',
        'J' => '000011100',
        'K' => '100000011',
        'L' => '001000011',
        'M' => '101000010',
        'N' => '000010011',
        'O' => '100010010',
        'P' => '001010010',
        'Q' => '000000111',
        'R' => '100000110',
        'S' => '001000110',
        'T' => '000010110',
        'U' => '110000001',
        'V' => '011000001',
        'W' => '111000000',
        'X' => '010010001',
        'Y' => '110010000',
        'Z' => '011010000',
        '-' => '010000101',
        '.' => '110000100',
        ' ' => '011000100',
        '*' => '010010100',
        '$' => '010101000',
        '/' => '010100010',
        '+' => '010001010',
        '%' => '000101010',
    ];

    /**
     * Render a Code 39 barcode as an inline SVG.
     */
    public static function svg(string $value, int $height = 40, float $moduleWidth = 1.4): string
    {
        $value = strtoupper($value);
        $encoded = '*'.$value.'*';
        $narrow = $moduleWidth;
        $wide = $moduleWidth * 3;
        $gap = $moduleWidth;
        $x = 0;
        $bars = '';

        foreach (str_split($encoded) as $char) {
            $pattern = self::PATTERNS[$char] ?? null;

            if ($pattern === null) {
                continue;
            }

            foreach (str_split($pattern) as $index => $bit) {
                $width = $bit === '1' ? $wide : $narrow;
                $isBar = $index % 2 === 0;

                if ($isBar) {
                    $bars .= sprintf(
                        '<rect x="%.2f" y="0" width="%.2f" height="%d" fill="#000"/>',
                        $x,
                        $width,
                        $height,
                    );
                }

                $x += $width;
            }

            $x += $gap;
        }

        $width = max(1, (int) ceil($x));

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="%s">%s</svg>',
            $width,
            $height,
            $width,
            $height,
            e($value),
            $bars,
        );
    }
}
