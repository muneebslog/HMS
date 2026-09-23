<?php

use App\Enums\LabFieldRangeCategory;
use App\Enums\LabFieldType;
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
        ->category->toBe(LabFieldRangeCategory::General)
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

test('a new choice field stores its options and no ranges', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->set('newFieldName', 'HBsAg')
        ->set('newFieldType', 'choice')
        ->set('newFieldOptions', ' Reactive, Non-reactive , ,Reactive')
        ->set('newFieldMinValue', '1')
        ->call('createAndAttachField')
        ->assertHasNoErrors()
        ->assertSee('Non-reactive');

    $field = LabField::where('name', 'HBsAg')->firstOrFail();

    expect($field->type)->toBe(LabFieldType::Choice);
    expect($field->options)->toBe(['Reactive', 'Non-reactive']);
    expect($field->ranges)->toHaveCount(0);
    expect($labTest->fields()->where('lab_field_id', $field->id)->exists())->toBeTrue();
});

test('a choice field requires at least one option', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->set('newFieldName', 'Blood Group')
        ->set('newFieldType', 'choice')
        ->set('newFieldOptions', ' , ')
        ->call('createAndAttachField')
        ->assertHasErrors('newFieldOptions');

    expect(LabField::where('name', 'Blood Group')->exists())->toBeFalse();
});

test('a new text field can be created', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->set('newFieldName', 'Donor Name')
        ->set('newFieldType', 'text')
        ->call('createAndAttachField')
        ->assertHasNoErrors();

    $field = LabField::where('name', 'Donor Name')->firstOrFail();

    expect($field->type)->toBe(LabFieldType::Text);
    expect($field->options)->toBeNull();
});

test('new fields default to numeric', function () {
    $field = LabField::create(['name' => 'Calcium']);

    expect($field->fresh()->type)->toBe(LabFieldType::Numeric);
});

test('the edit modal loads a choice field type and options', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();
    $field = LabField::factory()->choice(['Positive', 'Negative'])->create();
    $labTest->fields()->attach($field->id, ['display_order' => 1]);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->call('openEditFieldModal', $field->id)
        ->assertSet('editFieldType', 'choice')
        ->assertSet('editFieldOptions', 'Positive, Negative');
});

test('changing a numeric field to choice removes its ranges', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();
    $field = LabField::factory()->create(['name' => 'Malaria Parasite']);
    $field->ranges()->create(['category' => 'general', 'value_low' => '0', 'value_high' => '1']);
    $labTest->fields()->attach($field->id, ['display_order' => 1]);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.test-fields', ['labTest' => $labTest])
        ->call('openEditFieldModal', $field->id)
        ->set('editFieldType', 'choice')
        ->set('editFieldOptions', 'Seen, Not seen')
        ->call('updateField')
        ->assertHasNoErrors();

    $field->refresh();

    expect($field->type)->toBe(LabFieldType::Choice);
    expect($field->options)->toBe(['Seen', 'Not seen']);
    expect($field->ranges()->count())->toBe(0);
});

test('lab technicians can visit the all fields page and see linked tests', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create(['test_name' => 'Complete Blood Count']);
    $field = LabField::factory()->create(['name' => 'Hemoglobin']);
    $field->ranges()->create(['category' => 'general', 'value_low' => '12', 'value_high' => '16']);
    $labTest->fields()->attach($field->id, ['display_order' => 1]);

    $response = $this->actingAs($labTechnician)->get(route('lab.fields'));

    $response->assertOk();
    $response->assertSee('Hemoglobin');
    $response->assertSee('Complete Blood Count');
    $response->assertSee('12–16');
});

test('the all fields page shows choice options', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    LabField::factory()->choice(['Reactive', 'Non-Reactive'])->create(['name' => 'HBsAg']);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.fields')
        ->assertSee('HBsAg')
        ->assertSee('Non-Reactive');
});

test('the all fields page can be filtered by type and search', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    LabField::factory()->create(['name' => 'Calcium']);
    LabField::factory()->choice()->create(['name' => 'Blood Group']);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.fields')
        ->set('type', 'choice')
        ->assertSee('Blood Group')
        ->assertDontSee('Calcium')
        ->set('type', '')
        ->set('search', 'calc')
        ->assertSee('Calcium')
        ->assertDontSee('Blood Group');
});

test('the all fields page can show only unlinked fields', function () {
    $labTechnician = User::factory()->labTechnician()->create();
    $labTest = LabTest::factory()->create();
    $linked = LabField::factory()->create(['name' => 'Linked field']);
    LabField::factory()->create(['name' => 'Orphan field']);
    $labTest->fields()->attach($linked->id, ['display_order' => 1]);

    Livewire::actingAs($labTechnician)
        ->test('pages::lab.fields')
        ->set('unlinkedOnly', true)
        ->assertSee('Orphan field')
        ->assertDontSee('Linked field');
});

test('doctors cannot visit the all fields page', function () {
    $doctor = User::factory()->doctor()->create();

    $this->actingAs($doctor)->get(route('lab.fields'))->assertForbidden();
});
