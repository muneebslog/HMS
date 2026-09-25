<?php

use App\Support\PriceShorthand;

test('price codes turn into amounts', function (string $input, float $expected) {
    expect(PriceShorthand::parse($input))->toBe($expected);
})->with([
    'plain number' => ['1200', 1200.0],
    'decimal' => ['1200.50', 1200.5],
    'z is a hundred' => ['z', 100.0],
    'y is fifty' => ['y', 50.0],
    'number of hundreds' => ['12z', 1200.0],
    'hundred and fifty' => ['yz', 150.0],
    'fifty and hundred' => ['zy', 150.0],
    'hundreds plus fifty' => ['12zy', 1250.0],
    'hundreds plus a number' => ['12z50', 1250.0],
    'capital letters and spaces' => [' 12Z Y ', 1250.0],
    'thousands separator' => ['1,200', 1200.0],
]);

test('anything else is not a price', function (?string $input) {
    expect(PriceShorthand::parse($input))->toBeNull();
})->with([
    'empty' => [''],
    'null' => [null],
    'unknown letter' => ['12x'],
    'words' => ['twelve'],
    'letters only' => ['abc'],
]);
