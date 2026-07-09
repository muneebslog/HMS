<?php

use App\Models\LabTest;
use Database\Seeders\LabTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('lab test seeder imports data from labtests.json', function () {
    $this->seed(LabTestSeeder::class);

    expect(LabTest::count())->toBeGreaterThan(0);

    $cbc = LabTest::where('test_name', 'CBC')->first();

    expect($cbc)
        ->not->toBeNull()
        ->test_code->toBe('1300')
        ->test_price->toBe(700.00)
        ->sample->toBe('E.D.T.A 2cc')
        ->time_required->toBe('Same day')
        ->is_in_house->toBeTrue();
});

test('lab test seeder maps outsource sourcing to send out tests', function () {
    $this->seed(LabTestSeeder::class);

    $esr = LabTest::where('test_name', 'ESR')->first();

    expect($esr)
        ->not->toBeNull()
        ->test_code->toBeNull()
        ->is_in_house->toBeFalse();
});

test('lab test seeder is idempotent', function () {
    $this->seed(LabTestSeeder::class);
    $countAfterFirstRun = LabTest::count();

    $this->seed(LabTestSeeder::class);
    $countAfterSecondRun = LabTest::count();

    expect($countAfterSecondRun)->toBe($countAfterFirstRun);
});
