<?php

use App\Models\LabField;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabResult;
use App\Models\LabTest;
use App\Models\Patient;
use App\Services\OldLabResultsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Render rows as a phpMyAdmin-style INSERT statement.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function oldDumpInsert(string $table, array $rows): string
{
    $columns = '`'.implode('`, `', array_keys($rows[0])).'`';
    $tuples = array_map(function (array $row) {
        $values = array_map(fn ($value) => $value === null ? 'NULL' : (is_int($value) ? (string) $value : "'".addslashes((string) $value)."'"), $row);

        return '('.implode(', ', $values).')';
    }, $rows);

    return "INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $tuples).";\n\n";
}

/**
 * Write a small fake old-lab dump to a temp file and return its path.
 *
 * @param  array<string, list<array<string, mixed>>>  $tables
 */
function writeOldDump(array $tables): string
{
    $sql = "-- phpMyAdmin SQL Dump\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n\n";
    $sql .= "INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES\n('a', 'i:1;', 1);\n\n";

    foreach ($tables as $table => $rows) {
        $sql .= oldDumpInsert($table, $rows);
    }

    $path = tempnam(sys_get_temp_dir(), 'oldlab').'.sql';
    file_put_contents($path, $sql);

    return $path;
}

beforeEach(function () {
    // HMS tests and fields
    $this->hb = LabField::factory()->create(['name' => 'HB', 'unit' => 'g/dL']);
    $this->wbc = LabField::factory()->create(['name' => 'WBC']);
    $this->hbsag = LabField::factory()->choice(['Reactive', 'Non-Reactive'])->create(['name' => 'HBsAg']);
    $this->antiHcv = LabField::factory()->choice(['Reactive', 'Non-Reactive'])->create(['name' => 'Anti HCV']);
    $this->turbidity = LabField::factory()->choice(['Clear', 'Slightly Turbid', 'Turbid'])->create(['name' => 'Turbidity']);
    $this->nitrite = LabField::factory()->choice(['Negative', 'Positive'])->create(['name' => 'Nitrite']);
    $this->crystals = LabField::factory()->text()->create(['name' => 'Crystals']);
    $this->urea = LabField::factory()->create(['name' => 'Urea']);
    $this->ureaSerum = LabField::factory()->create(['name' => 'Urea (Serum)']);
    $this->creatinine = LabField::factory()->create(['name' => 'Creatinine']);

    $makeTest = function (string $name, string $code, array $fields) {
        $test = LabTest::factory()->create(['test_name' => $name, 'test_code' => $code, 'is_in_house' => true]);
        foreach ($fields as $i => $field) {
            $test->fields()->attach($field->id, ['display_order' => $i + 1]);
        }

        return $test;
    };

    $this->cbc = $makeTest('CBC', '1300', [$this->hb, $this->wbc]);
    $this->hbsagTest = $makeTest('HbsAg', '4235', [$this->hbsag]);
    $this->antiHcvTest = $makeTest('Anti HCV', '4235', [$this->antiHcv]);
    $this->urine = $makeTest('Urine C/E', '1122', [$this->turbidity, $this->nitrite, $this->crystals]);
    $this->rft = $makeTest("RFT's", '1618', [$this->urea, $this->ureaSerum, $this->creatinine]);

    $this->case = function (string $receipt, string $name, ?string $gender, array $tests, array $invoiceAttributes = []) {
        $patient = Patient::factory()->create(['name' => $name, 'gender' => $gender, 'age' => 30]);
        $invoice = LabInvoice::factory()->paid()->create(['patient_id' => $patient->id, 'invoice_number' => $receipt, ...$invoiceAttributes]);
        $items = [];
        foreach ($tests as $test) {
            $items[$test->test_name] = LabInvoiceItem::factory()->inHouse()->create([
                'lab_invoice_id' => $invoice->id,
                'lab_test_id' => $test->id,
                'test_name' => $test->test_name,
                'test_code' => $test->test_code,
            ]);
        }

        return $items;
    };

    // Old software rows (mirrors the real dump's layout)
    $this->oldTests = [
        ['id' => 17, 'name' => 'Complete Blood Count', 'code' => '1300'],
        ['id' => 33, 'name' => 'Screening', 'code' => '4235'],
        ['id' => 12, 'name' => 'Urine', 'code' => '1122'],
        ['id' => 7, 'name' => 'RENAL FUNCTION TEST', 'code' => '1618'],
    ];
    $this->oldFields = [
        ['id' => 3, 'field_name' => ' WBC ', 'unit' => 'x10^9/l'],
        ['id' => 5, 'field_name' => 'HB', 'unit' => 'g/dl'],
        ['id' => 110, 'field_name' => 'HbsAg', 'unit' => '.'],
        ['id' => 67, 'field_name' => 'Anti HCV', 'unit' => 'Nill'],
        ['id' => 43, 'field_name' => 'Turbidity', 'unit' => '0'],
        ['id' => 52, 'field_name' => 'Nitrite:', 'unit' => '0'],
        ['id' => 57, 'field_name' => 'Crystals', 'unit' => '0'],
        ['id' => 25, 'field_name' => 'UREA (Serum)', 'unit' => 'mg/dl'],
        ['id' => 26, 'field_name' => 'CREATININE', 'unit' => 'mg/dl'],
    ];
    $this->oldLinks = [
        ['test_id' => 17, 'test_field_id' => 3], ['test_id' => 17, 'test_field_id' => 5],
        ['test_id' => 33, 'test_field_id' => 110], ['test_id' => 33, 'test_field_id' => 67],
        ['test_id' => 12, 'test_field_id' => 43], ['test_id' => 12, 'test_field_id' => 52], ['test_id' => 12, 'test_field_id' => 57],
        ['test_id' => 7, 'test_field_id' => 25], ['test_id' => 7, 'test_field_id' => 26],
    ];
});

/**
 * Build the old dump for the given old patients, patient tests and results.
 */
function oldDumpFor($testCase, array $patients, array $patientTests, array $results): string
{
    return writeOldDump([
        'patients' => $patients,
        'tests' => $testCase->oldTests,
        'test_fields' => $testCase->oldFields,
        'test_test_field' => $testCase->oldLinks,
        'patient_test' => $patientTests,
        'test_results' => $results,
    ]);
}

function oldPatient(int $id, string $receipt, string $name, string $gender = 'female'): array
{
    return ['id' => $id, 'name' => $name, 'gender' => $gender, 'age' => 30, 'receipt_no' => $receipt, 'created_at' => '2026-09-20 08:00:00'];
}

function oldPatientTest(int $id, int $patientId, int $testId, int $resultAdded = 1, string $updatedAt = '2026-09-20 09:00:00'): array
{
    return ['id' => $id, 'patient_id' => $patientId, 'test_id' => $testId, 'isResultAdded' => $resultAdded, 'created_at' => '2026-09-20 08:00:00', 'updated_at' => $updatedAt];
}

function oldResult(int $id, int $patientTestId, int $fieldId, string $value): array
{
    return ['id' => $id, 'patient_test_id' => $patientTestId, 'test_field_id' => $fieldId, 'result' => $value];
}

test('the dump parser reads rows, trims values and handles escaped quotes and nulls', function () {
    $path = oldDumpFor($this, [oldPatient(1, ' 928001', "AYESHA O'BRIEN")], [oldPatientTest(1, 1, 17)], [oldResult(1, 1, 5, ' 10.2')]);

    $old = app(OldLabResultsImporter::class)->parseDump($path);

    expect($old['patients'][0])->toMatchArray(['receipt_no' => '928001', 'name' => "AYESHA O'BRIEN"])
        ->and($old['test_results'][0]['result'])->toBe('10.2')
        ->and($old['test_fields'])->toHaveCount(9);
});

test('a dry run plans the import and writes nothing', function () {
    ($this->case)('928001', 'Ayesha Khan', 'female', [$this->cbc]);
    $path = oldDumpFor($this, [oldPatient(1, '928001', 'AYESHA  KHAN')], [oldPatientTest(1, 1, 17)], [oldResult(1, 1, 5, '10.2'), oldResult(2, 1, 3, '7.4')]);

    $this->artisan('lab:import-old-results', ['dump' => $path])
        ->expectsOutputToContain('1 test(s) ready to import, 2 value(s).')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(LabResult::count())->toBe(0);
});

test('results are imported with values translated and the test completed at the old time', function () {
    $items = ($this->case)('928001', 'Ayesha Khan', 'female', [$this->cbc, $this->hbsagTest, $this->antiHcvTest, $this->urine]);
    $path = oldDumpFor($this, [oldPatient(1, '928001', 'AYESHA KHAN')], [
        oldPatientTest(1, 1, 17),
        oldPatientTest(2, 1, 33),
        oldPatientTest(3, 1, 12),
    ], [
        oldResult(1, 1, 5, '10.2'),
        oldResult(2, 1, 3, 'Id omnis unde exerci'),
        oldResult(3, 2, 110, 'Negative'),
        oldResult(4, 2, 67, 'Positive'),
        oldResult(5, 3, 43, '+'),
        oldResult(6, 3, 52, 'Nil'),
        oldResult(7, 3, 57, ' Nil '),
    ]);

    $this->artisan('lab:import-old-results', ['dump' => $path, '--apply' => true])
        ->expectsOutputToContain('Imported results for 4 test(s).')
        ->assertSuccessful();

    $values = fn (LabInvoiceItem $item) => $item->results()->pluck('value', 'lab_field_id')->all();

    expect($values($items['CBC']))->toBe([$this->hb->id => '10.2'])
        ->and($values($items['HbsAg']))->toBe([$this->hbsag->id => 'Non-Reactive'])
        ->and($values($items['Anti HCV']))->toBe([$this->antiHcv->id => 'Reactive'])
        ->and($values($items['Urine C/E']))->toBe([
            $this->turbidity->id => 'Slightly Turbid',
            $this->nitrite->id => 'Negative',
            $this->crystals->id => 'Nil',
        ]);

    $cbc = $items['CBC']->fresh();

    expect($cbc->isDone())->toBeTrue()
        ->and($cbc->results_completed_by)->toBeNull()
        ->and($cbc->results_imported_at)->not->toBeNull()
        ->and($cbc->results_completed_at->format('Y-m-d H:i'))->toBe('2026-09-20 14:00')
        ->and($cbc->results()->first()->entered_by)->toBeNull();
});

test('uncertain matches are skipped', function () {
    ($this->case)('928002', 'Jameela', 'female', [$this->cbc]);
    ($this->case)('928003', 'Ali Raza', 'male', [$this->cbc]);
    ($this->case)('928004', 'Returned Patient', 'female', [$this->cbc], ['status' => 'returned']);
    ($this->case)('928006', 'Pending Old', 'female', [$this->cbc]);
    ($this->case)('928008', 'No Such Receipt', 'female', [$this->cbc]);
    $emptyCode = ($this->case)('928009', 'Empty Code', 'female', [$this->cbc]);
    $emptyCode['CBC']->update(['test_code' => '']);

    $path = oldDumpFor($this, [
        oldPatient(2, '928002', 'NABEELA BIBI'),
        oldPatient(3, '928003', 'ALI RAZA', 'female'),
        oldPatient(4, '928004', 'RETURNED PATIENT'),
        oldPatient(6, '928006', 'PENDING OLD'),
        oldPatient(9, '928009', 'EMPTY CODE'),
    ], [
        oldPatientTest(2, 2, 17),
        oldPatientTest(3, 3, 17),
        oldPatientTest(4, 4, 17),
        oldPatientTest(6, 6, 17, resultAdded: 0),
        oldPatientTest(9, 9, 17),
    ], [
        oldResult(2, 2, 5, '13'), oldResult(3, 3, 5, '13'), oldResult(4, 4, 5, '13'), oldResult(6, 6, 5, '13'), oldResult(9, 9, 5, '13'),
    ]);

    $plan = app(OldLabResultsImporter::class)->plan(app(OldLabResultsImporter::class)->parseDump($path));

    expect($plan['items'])->toBe([])
        ->and(array_keys($plan['skipped']))->toEqualCanonicalizing([
            'patient name does not match',
            'patient sex does not match',
            'no results entered in old software',
            'receipt number not found in old software',
            'HMS test has no code',
        ]);
});

test('tests that already have HMS results are never touched', function () {
    $items = ($this->case)('928005', 'Has Results', 'female', [$this->cbc]);
    LabResult::factory()->create(['lab_invoice_item_id' => $items['CBC']->id, 'lab_field_id' => $this->hb->id, 'value' => '15']);

    $path = oldDumpFor($this, [oldPatient(5, '928005', 'HAS RESULTS')], [oldPatientTest(5, 5, 17)], [oldResult(5, 5, 5, '9')]);

    $this->artisan('lab:import-old-results', ['dump' => $path, '--apply' => true])->assertSuccessful();

    expect($items['CBC']->results()->pluck('value')->all())->toBe(['15'])
        ->and($items['CBC']->fresh()->results_imported_at)->toBeNull();
});

test('an old field that two HMS fields could claim is skipped', function () {
    $items = ($this->case)('928010', 'Kidney Patient', 'female', [$this->rft]);
    $path = oldDumpFor($this, [oldPatient(10, '928010', 'KIDNEY PATIENT')], [oldPatientTest(10, 10, 7)], [
        oldResult(10, 10, 25, '40'),
        oldResult(11, 10, 26, '0.9'),
    ]);

    $this->artisan('lab:import-old-results', ['dump' => $path, '--apply' => true])->assertSuccessful();

    expect($items["RFT's"]->results()->pluck('value', 'lab_field_id')->all())->toBe([$this->creatinine->id => '0.9']);
});

test('only cases since the chosen date are imported', function () {
    $recent = ($this->case)('928011', 'Recent Case', 'female', [$this->cbc]);
    $old = ($this->case)('928012', 'Old Case', 'female', [$this->cbc], ['created_at' => now()->subMonths(2)]);
    $path = oldDumpFor($this, [oldPatient(11, '928011', 'RECENT CASE'), oldPatient(12, '928012', 'OLD CASE')], [
        oldPatientTest(11, 11, 17), oldPatientTest(12, 12, 17),
    ], [oldResult(11, 11, 5, '13'), oldResult(12, 12, 5, '14')]);

    $this->artisan('lab:import-old-results', ['dump' => $path, '--apply' => true])->assertSuccessful();

    expect($recent['CBC']->fresh()->isDone())->toBeTrue()
        ->and($old['CBC']->fresh()->isDone())->toBeFalse();

    $this->artisan('lab:import-old-results', ['dump' => $path, '--apply' => true, '--since' => now()->subMonths(3)->toDateString()])->assertSuccessful();

    expect($old['CBC']->fresh()->isDone())->toBeTrue();
});

test('running the import twice imports nothing new', function () {
    ($this->case)('928001', 'Ayesha Khan', 'female', [$this->cbc]);
    $path = oldDumpFor($this, [oldPatient(1, '928001', 'AYESHA KHAN')], [oldPatientTest(1, 1, 17)], [oldResult(1, 1, 5, '10.2')]);

    $this->artisan('lab:import-old-results', ['dump' => $path, '--apply' => true])->expectsOutputToContain('Imported results for 1 test(s).');
    $this->artisan('lab:import-old-results', ['dump' => $path, '--apply' => true])->expectsOutputToContain('Imported results for 0 test(s).');

    expect(LabResult::count())->toBe(1);
});

test('rollback removes only imported results that were not edited in the HMS', function () {
    $imported = ($this->case)('928001', 'Ayesha Khan', 'female', [$this->cbc]);
    $edited = ($this->case)('928013', 'Edited Later', 'female', [$this->cbc]);
    $hmsOnly = ($this->case)('928014', 'Hms Only', 'female', [$this->cbc]);
    LabResult::factory()->create(['lab_invoice_item_id' => $hmsOnly['CBC']->id, 'lab_field_id' => $this->hb->id, 'value' => '12']);
    $hmsOnly['CBC']->update(['results_completed_at' => now()]);

    $path = oldDumpFor($this, [oldPatient(1, '928001', 'AYESHA KHAN'), oldPatient(13, '928013', 'EDITED LATER')], [
        oldPatientTest(1, 1, 17), oldPatientTest(13, 13, 17),
    ], [oldResult(1, 1, 5, '10.2'), oldResult(13, 13, 5, '11')]);

    $this->artisan('lab:import-old-results', ['dump' => $path, '--apply' => true])->assertSuccessful();

    // Editing in the HMS makes the test HMS-owned (as the case page does on save).
    $edited['CBC']->update(['results_imported_at' => null]);

    $this->artisan('lab:import-old-results', ['--rollback' => true])
        ->expectsOutputToContain('1 imported test(s) would be rolled back')
        ->assertSuccessful();

    $this->artisan('lab:import-old-results', ['--rollback' => true, '--apply' => true])
        ->expectsOutputToContain('Rolled back 1 imported test(s).')
        ->assertSuccessful();

    expect($imported['CBC']->fresh()->isDone())->toBeFalse()
        ->and($imported['CBC']->results()->count())->toBe(0)
        ->and($edited['CBC']->results()->count())->toBe(1)
        ->and($hmsOnly['CBC']->results()->count())->toBe(1)
        ->and($hmsOnly['CBC']->fresh()->isDone())->toBeTrue();
});
