<?php

use App\Enums\LabReportLayout;
use App\Models\LabField;
use App\Models\LabTest;
use App\Models\User;
use App\Services\LabReportBuilder;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('report settings can be saved with section headings', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();
    $color = LabField::factory()->text()->create(['name' => 'Color']);
    $pus = LabField::factory()->text()->create(['name' => 'Pus Cells']);
    $labTest->fields()->attach($color->id, ['display_order' => 1]);
    $labTest->fields()->attach($pus->id, ['display_order' => 2]);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->call('openReportSettingsModal')
        ->set('reportLayout', 'two_columns')
        ->set('reportNote', 'Method: dipstick and microscopy')
        ->set('reportShowRanges', false)
        ->set("fieldSections.{$color->id}", 'Physical')
        ->set("fieldSections.{$pus->id}", 'Microscopic')
        ->call('saveReportSettings')
        ->assertHasNoErrors();

    $labTest->refresh();

    expect($labTest->report_layout)->toBe(LabReportLayout::TwoColumns)
        ->and($labTest->report_note)->toBe('Method: dipstick and microscopy')
        ->and($labTest->report_show_ranges)->toBeFalse()
        ->and($labTest->fields->pluck('pivot.section', 'id')->all())->toBe([$color->id => 'Physical', $pus->id => 'Microscopic']);
});

test('choosing automatic clears the layout', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create(['report_layout' => LabReportLayout::Grid]);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->call('openReportSettingsModal')
        ->assertSet('reportLayout', 'grid')
        ->set('reportLayout', '')
        ->call('saveReportSettings')
        ->assertHasNoErrors();

    expect($labTest->fresh()->report_layout)->toBeNull();
});

test('a custom layout needs an existing template file', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->call('openReportSettingsModal')
        ->set('reportLayout', 'custom')
        ->set('reportCustomTemplate', 'does-not-exist')
        ->call('saveReportSettings')
        ->assertHasErrors('reportCustomTemplate')
        ->set('reportCustomTemplate', 'example')
        ->call('saveReportSettings')
        ->assertHasNoErrors();

    expect($labTest->fresh()->report_custom_template)->toBe('example');
});

test('the report preview renders each layout with sample values', function (string $layout) {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create(['test_name' => 'Preview Test', 'report_layout' => $layout, 'report_note' => 'Interpretation note']);
    $numeric = LabField::factory()->create(['name' => 'Hemoglobin']);
    $numeric->ranges()->create(['category' => 'general', 'value_low' => '12', 'value_high' => '16']);
    $titre = LabField::factory()->choice(['Negative', '1:20', '1:40', '1:80'])->create(['name' => 'S. Typhi O']);
    $labTest->fields()->attach($numeric->id, ['display_order' => 1]);
    $labTest->fields()->attach($titre->id, ['display_order' => 2]);

    $response = $this->actingAs($labTechnician)->get(route('lab.tests.report-preview', $labTest));

    $response->assertOk()
        ->assertSee('Preview Test')
        ->assertSee('Interpretation note')
        ->assertSee('Sample Patient');
})->with(['table', 'two_columns', 'compact', 'highlight', 'grid', 'narrative']);

test('the report preview shows a high flag and the normal range', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create(['report_layout' => LabReportLayout::Table]);
    $field = LabField::factory()->create(['name' => 'Hemoglobin']);
    $field->ranges()->create(['category' => 'general', 'value_low' => '12.00', 'value_high' => '16.00']);
    $labTest->fields()->attach($field->id, ['display_order' => 1]);

    $this->actingAs($labTechnician)
        ->get(route('lab.tests.report-preview', $labTest))
        ->assertOk()
        ->assertSee('12 – 16')
        ->assertSee('18.4');
});

test('the report prints the lab letterhead, disclaimer and signatories', function () {
    config([
        'hospital.lab.brand' => 'Mohsin',
        'hospital.lab.registration' => 'PHC REG # R 13048',
        'hospital.lab.disclaimer' => 'Electronically verified report.',
        'hospital.lab.signatories' => [['name' => 'Dr. Tariq Saeed', 'qualification' => 'F.C.P.S', 'title' => 'Asst. Prof. Surgery']],
    ]);
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();
    $labTest->fields()->attach(LabField::factory()->create()->id, ['display_order' => 1]);

    $this->actingAs($labTechnician)
        ->get(route('lab.tests.report-preview', $labTest))
        ->assertOk()
        ->assertSee('Mohsin')
        ->assertSee('PHC REG # R 13048')
        ->assertSee('Electronically verified report.')
        ->assertSee('Dr. Tariq Saeed')
        ->assertSee('Asst. Prof. Surgery')
        ->assertSee('MRN000000');
});

test('each test is printed on its own A4 sheet with the letterhead and footer', function () {
    $builder = app(LabReportBuilder::class);
    $sections = collect(range(1, 3))->map(function (int $number) use ($builder) {
        $labTest = LabTest::factory()->create(['test_name' => "Test {$number}"]);
        $field = LabField::factory()->create();
        $labTest->fields()->attach($field->id, ['display_order' => 1]);

        return $builder->buildSection($labTest, [$field->id => '5']);
    })->all();

    $html = view('lab.reports.report', [
        'header' => ['title' => 'Laboratory Report', 'number' => '1', 'mrn' => null, 'patient_name' => 'Test', 'age_sex' => null, 'phone' => null, 'referred_by' => null, 'sample_date' => null, 'report_date' => null],
        'sections' => $sections,
    ])->render();

    expect(substr_count($html, 'class="sheet"'))->toBe(3)
        ->and(substr_count($html, 'class="letterhead"'))->toBe(3)
        ->and(substr_count($html, 'class="page-footer"'))->toBe(3)
        ->and(substr_count($html, 'class="test"'))->toBe(3);
});

test('doctors cannot open the report preview', function () {
    $doctor = User::factory()->doctor()->create();
    $labTest = LabTest::factory()->create();

    $this->actingAs($doctor)->get(route('lab.tests.report-preview', $labTest))->assertForbidden();
});

test('a test display name can be set in report settings and is shown on the tests list', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create(['test_name' => 'CBC']);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->call('openReportSettingsModal')
        ->assertSet('reportDisplayName', '')
        ->set('reportDisplayName', ' Complete Blood Count ')
        ->call('saveReportSettings')
        ->assertHasNoErrors();

    expect($labTest->fresh()->display_name)->toBe('Complete Blood Count');

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.tests')
        ->set('search', 'blood count')
        ->assertSee('CBC')
        ->assertSee('Complete Blood Count');
});
