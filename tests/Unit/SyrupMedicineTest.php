<?php

use App\Models\Medicine;

test('medicine names starting with syp. are identified as syrups', function () {
    expect(Medicine::nameIsSyrup('Syp. Phenergan'))->toBeTrue()
        ->and(Medicine::nameIsSyrup('syp. cough'))->toBeTrue()
        ->and(Medicine::nameIsSyrup('  SYP. Calpol'))->toBeTrue()
        ->and(Medicine::nameIsSyrup('Tab. Panadol'))->toBeFalse()
        ->and(Medicine::nameIsSyrup('Phenergan Syrup'))->toBeFalse();
});
