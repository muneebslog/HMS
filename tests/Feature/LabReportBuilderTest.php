<?php

use App\Enums\LabReportLayout;
use App\Models\LabField;
use App\Models\LabTest;
use App\Services\LabReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->builder = app(LabReportBuilder::class);
});

/**
 * Attach fields to a test in the given order, with optional section headings.
 *
 * @param  array<int, array{0: LabField, 1?: string|null}>  $fields
 */
function attachFields(LabTest $labTest, array $fields): void
{
    foreach ($fields as $index => $entry) {
        $labTest->fields()->attach($entry[0]->id, ['display_order' => $index + 1, 'section' => $entry[1] ?? null]);
    }
}

test('empty results are left out and a test with no results builds no section', function () {
    $labTest = LabTest::factory()->create(['test_name' => 'CBC']);
    $hb = LabField::factory()->create(['name' => 'HB']);
    $wbc = LabField::factory()->create(['name' => 'WBC']);
    attachFields($labTest, [[$hb], [$wbc]]);

    $section = $this->builder->buildSection($labTest, [$hb->id => '13', $wbc->id => '  ']);

    expect($section['rows'])->toHaveCount(1)
        ->and($section['rows'][0]['field'])->toBe('HB');

    expect($this->builder->buildSection($labTest, [$hb->id => null, $wbc->id => '']))->toBeNull();
});

test('section headings group consecutive fields and empty headings are dropped', function () {
    $labTest = LabTest::factory()->create();
    $color = LabField::factory()->text()->create(['name' => 'Color']);
    $sugar = LabField::factory()->text()->create(['name' => 'Sugar']);
    $pus = LabField::factory()->text()->create(['name' => 'Pus Cells']);
    attachFields($labTest, [[$color, 'Physical'], [$sugar, 'Chemical'], [$pus, 'Microscopic']]);

    $section = $this->builder->buildSection($labTest, [$color->id => 'Yellow', $pus->id => '2-4']);

    expect(array_column($section['groups'], 'heading'))->toBe(['Physical', 'Microscopic']);
});

test('values outside the range are flagged high or low', function () {
    $labTest = LabTest::factory()->create();
    $field = LabField::factory()->create();
    $field->ranges()->create(['category' => 'general', 'value_low' => '12.00', 'value_high' => '16.00']);
    attachFields($labTest, [[$field]]);

    $flagFor = fn (string $value) => $this->builder->buildSection($labTest->fresh(), [$field->id => $value])['rows'][0]['flag'];

    expect($flagFor('17'))->toBe(LabReportBuilder::FLAG_HIGH)
        ->and($flagFor('10.5'))->toBe(LabReportBuilder::FLAG_LOW)
        ->and($flagFor('14'))->toBeNull()
        ->and($flagFor('clotted'))->toBeNull();
});

test('time ranges in minutes and seconds are compared correctly', function () {
    $labTest = LabTest::factory()->create();
    $field = LabField::factory()->create(['name' => 'Clotting Time', 'unit' => 'Min:Sec']);
    $field->ranges()->create(['category' => 'general', 'value_low' => '4:00', 'value_high' => '11:00']);
    attachFields($labTest, [[$field]]);

    $section = $this->builder->buildSection($labTest, [$field->id => '12:30']);

    expect($section['rows'][0]['flag'])->toBe(LabReportBuilder::FLAG_HIGH);
});

test('range trailing zeros are trimmed for display', function () {
    $labTest = LabTest::factory()->create();
    $field = LabField::factory()->create();
    $field->ranges()->create(['category' => 'general', 'value_low' => '0.80', 'value_high' => '12.00']);
    attachFields($labTest, [[$field]]);

    $section = $this->builder->buildSection($labTest, [$field->id => '5']);

    expect($section['rows'][0]['range'])->toBe('0.8 – 12');
});

test('the range is chosen by child age, then gender, then general', function () {
    $field = LabField::factory()->create();
    $field->ranges()->createMany([
        ['category' => 'general', 'value_low' => '1', 'value_high' => '2'],
        ['category' => 'female', 'value_low' => '3', 'value_high' => '4'],
        ['category' => 'child', 'value_low' => '5', 'value_high' => '6'],
    ]);

    expect($this->builder->selectRange($field, 'female', 5)->value_low)->toBe('5')
        ->and($this->builder->selectRange($field, 'female', 30)->value_low)->toBe('3')
        ->and($this->builder->selectRange($field, 'male', 30)->value_low)->toBe('1')
        ->and($this->builder->selectRange($field, null, null)->value_low)->toBe('1');
});

test('single-field tests default to compact and others to table', function () {
    $single = LabTest::factory()->create();
    attachFields($single, [[LabField::factory()->create()]]);

    $multi = LabTest::factory()->create();
    attachFields($multi, [[LabField::factory()->create()], [LabField::factory()->create()]]);

    $chosen = LabTest::factory()->create(['report_layout' => LabReportLayout::Highlight]);
    attachFields($chosen, [[LabField::factory()->create()]]);

    expect($single->resolvedReportLayout())->toBe(LabReportLayout::Compact)
        ->and($multi->resolvedReportLayout())->toBe(LabReportLayout::Table)
        ->and($chosen->resolvedReportLayout())->toBe(LabReportLayout::Highlight);
});

test('grid cells fill titre columns up to the chosen option', function () {
    $row = ['field' => 'S. Typhi O', 'unit' => null, 'value' => '1:80', 'range' => null, 'flag' => null, 'options' => ['Negative', '1:20', '1:40', '1:80', '1:160']];

    expect($this->builder->gridCells($row))->toBe([
        'columns' => ['1:20', '1:40', '1:80', '1:160'],
        'filled' => 3,
    ]);

    expect($this->builder->gridCells([...$row, 'value' => 'Negative'])['filled'])->toBe(0);
});

test('sample values fill every field and push one numeric value above its range', function () {
    $labTest = LabTest::factory()->create();
    $numeric = LabField::factory()->create();
    $numeric->ranges()->create(['category' => 'general', 'value_low' => '10', 'value_high' => '20']);
    $choice = LabField::factory()->choice(['Reactive', 'Non-Reactive'])->create();
    $text = LabField::factory()->text()->create();
    attachFields($labTest, [[$numeric], [$choice], [$text]]);

    $values = $this->builder->sampleValues($labTest);
    $section = $this->builder->buildSection($labTest, $values);

    expect($section['rows'])->toHaveCount(3)
        ->and($values[$choice->id])->toBe('Reactive')
        ->and($section['rows'][0]['flag'])->toBe(LabReportBuilder::FLAG_HIGH);
});
