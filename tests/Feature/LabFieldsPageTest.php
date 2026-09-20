<?php

use App\Models\LabField;
use App\Models\LabTest;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('lab technicians can visit the lab tests list', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();

    $response = $this->actingAs($labTechnician)->get(route('lab.tests'));

    $response->assertOk();
    $response->assertSee($labTest->test_name);
});

test('lab technicians can open a test fields page', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();

    $response = $this->actingAs($labTechnician)->get(route('lab.tests.fields', $labTest));

    $response->assertOk();
    $response->assertSee($labTest->test_name);
});

test('the in-house filter on the lab tests list hides outgoing tests', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $inHouse = LabTest::factory()->create(['test_name' => 'In-house CBC', 'is_in_house' => true]);
    $outgoing = LabTest::factory()->create(['test_name' => 'Outgoing MRI', 'is_in_house' => false]);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.tests')
        ->assertSee('In-house CBC')
        ->assertSee('Outgoing MRI')
        ->set('inHouseOnly', true)
        ->assertSee('In-house CBC')
        ->assertDontSee('Outgoing MRI');
});

test('doctors cannot visit the lab tests pages', function () {
    $doctor = User::factory()->doctor()->create();
    $labTest = LabTest::factory()->create();

    $this->actingAs($doctor)->get(route('lab.tests'))->assertForbidden();
    $this->actingAs($doctor)->get(route('lab.tests.fields', $labTest))->assertForbidden();
});

test('an existing field can be attached to a test', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();
    $field = LabField::factory()->create(['name' => 'Hemoglobin']);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->set('fieldIdsToAttach', [$field->id])
        ->call('attachExistingField')
        ->assertSee('Hemoglobin');

    expect($labTest->fields()->where('lab_field_id', $field->id)->exists())->toBeTrue();
});

test('multiple existing fields can be attached to a test at once', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();
    $first = LabField::factory()->create(['name' => 'Hemoglobin']);
    $second = LabField::factory()->create(['name' => 'WBC count']);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->set('fieldIdsToAttach', [$first->id, $second->id])
        ->call('attachExistingField')
        ->assertSee('Hemoglobin')
        ->assertSee('WBC count');

    expect($labTest->fields()->where('lab_field_id', $first->id)->exists())->toBeTrue();
    expect($labTest->fields()->where('lab_field_id', $second->id)->exists())->toBeTrue();
});

test('a new field can be created with a single general range and attached to a test', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->set('newFieldName', 'Platelet count')
        ->set('newFieldUnit', 'x10^3/uL')
        ->set('newFieldMinValue', '150')
        ->set('newFieldMaxValue', '450')
        ->call('createAndAttachField')
        ->assertSee('Platelet count');

    $field = LabField::where('name', 'Platelet count')->firstOrFail();

    expect($labTest->fields()->where('lab_field_id', $field->id)->exists())->toBeTrue();
    expect($field->ranges)->toHaveCount(1);
    expect($field->ranges->first())
        ->category->toBe(\App\Enums\LabFieldRangeCategory::General)
        ->value_low->toBe('150')
        ->value_high->toBe('450');
});

test('a range value can be a non-numeric time format', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->set('newFieldName', 'Bleeding Time')
        ->set('newFieldUnit', 'Minutes:Seconds')
        ->set('newFieldMinValue', '02:00')
        ->set('newFieldMaxValue', '07:00')
        ->call('createAndAttachField')
        ->assertHasNoErrors()
        ->assertSee('Bleeding Time');

    $field = LabField::where('name', 'Bleeding Time')->firstOrFail();

    expect($field->ranges->first())
        ->value_low->toBe('02:00')
        ->value_high->toBe('07:00');
});

test('a new field can be created with per-category ranges and attached to a test', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->set('newFieldName', 'WBC count')
        ->set('newFieldUnit', 'x10^3/uL')
        ->set('newFieldHasMultipleRanges', true)
        ->set('newFieldRanges', [
            ['category' => 'male', 'value_low' => '4', 'value_high' => '11'],
            ['category' => 'female', 'value_low' => '4', 'value_high' => '11'],
        ])
        ->call('createAndAttachField')
        ->assertSee('WBC count');

    $field = LabField::where('name', 'WBC count')->firstOrFail();

    expect($labTest->fields()->where('lab_field_id', $field->id)->exists())->toBeTrue();
    expect($field->ranges)->toHaveCount(2);
    expect($field->ranges->firstWhere('category', 'male'))
        ->value_low->toBe('4')
        ->value_high->toBe('11');
});

test('a field can be detached from a test', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();
    $field = LabField::factory()->create();
    $labTest->fields()->attach($field->id, ['display_order' => 1]);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->call('detachField', $field->id);

    expect($labTest->fields()->where('lab_field_id', $field->id)->exists())->toBeFalse();
});

test('fields can be reordered on a test', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();
    $first = LabField::factory()->create(['name' => 'First field']);
    $second = LabField::factory()->create(['name' => 'Second field']);
    $labTest->fields()->attach($first->id, ['display_order' => 1]);
    $labTest->fields()->attach($second->id, ['display_order' => 2]);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->call('moveFieldDown', $first->id);

    $orderedIds = $labTest->fields()->orderBy('display_order')->pluck('lab_fields.id')->all();

    expect($orderedIds)->toBe([$second->id, $first->id]);
});
