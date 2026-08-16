<?php

use App\Enums\TokenResetType;
use App\Models\DripBase;
use App\Models\Injection;
use App\Models\Medicine;
use App\Models\Symptom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('authenticated admins can create a medicine', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'medicines')
        ->call('create')
        ->set('medicineBulkRows.0.name', 'Paracetamol')
        ->set('medicineBulkRows.0.short_form', 'PCM')
        ->set('medicineBulkRows.0.unit', 'tablet')
        ->set('medicineBulkRows.0.default_dose', '1-0-1')
        ->set('medicineBulkRows.0.default_days', '5')
        ->set('medicineBulkRows.0.is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('medicines', [
        'name' => 'Paracetamol',
        'short_form' => 'PCM',
        'unit' => 'tablet',
        'default_dose' => '1-0-1',
        'default_days' => 5,
        'is_active' => true,
    ]);
});

test('authenticated admins can bulk create medicines', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'medicines')
        ->call('create')
        ->assertCount('medicineBulkRows', 5)
        ->call('addMedicineBulkRow')
        ->assertCount('medicineBulkRows', 6)
        ->set('medicineBulkRows.0.name', 'Paracetamol')
        ->set('medicineBulkRows.0.short_form', 'PCM')
        ->set('medicineBulkRows.0.unit', 'tablet')
        ->set('medicineBulkRows.1.name', 'Ibuprofen')
        ->set('medicineBulkRows.1.unit', 'tablet')
        ->set('medicineBulkRows.2.name', '')
        ->set('medicineBulkRows.2.unit', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Medicine::query()->count())->toBe(2);

    $this->assertDatabaseHas('medicines', [
        'name' => 'Paracetamol',
        'short_form' => 'PCM',
        'unit' => 'tablet',
    ]);

    $this->assertDatabaseHas('medicines', [
        'name' => 'Ibuprofen',
        'unit' => 'tablet',
    ]);
});

test('authenticated admins can create an injection', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'injections')
        ->call('create')
        ->set('injectionBulkRows.0.name', 'Diclofenac')
        ->set('injectionBulkRows.0.short_form', 'DIC')
        ->set('injectionBulkRows.0.default_administration_type', 'iv')
        ->set('injectionBulkRows.0.is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('injections', [
        'name' => 'Diclofenac',
        'short_form' => 'DIC',
        'default_administration_type' => 'iv',
        'is_active' => true,
    ]);
});

test('authenticated admins can bulk create injections', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'injections')
        ->call('create')
        ->assertCount('injectionBulkRows', 5)
        ->call('addInjectionBulkRow')
        ->assertCount('injectionBulkRows', 6)
        ->set('injectionBulkRows.0.name', 'Diclofenac')
        ->set('injectionBulkRows.0.short_form', 'DIC')
        ->set('injectionBulkRows.0.default_administration_type', 'iv')
        ->set('injectionBulkRows.1.name', 'Ceftriaxone')
        ->set('injectionBulkRows.1.default_administration_type', 'im')
        ->call('save')
        ->assertHasNoErrors();

    expect(Injection::query()->count())->toBe(2);

    $this->assertDatabaseHas('injections', [
        'name' => 'Diclofenac',
        'short_form' => 'DIC',
        'default_administration_type' => 'iv',
    ]);

    $this->assertDatabaseHas('injections', [
        'name' => 'Ceftriaxone',
        'default_administration_type' => 'im',
    ]);
});

test('bulk medicine create allows a blank unit', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'medicines')
        ->call('create')
        ->set('medicineBulkRows.0.name', 'Paracetamol')
        ->set('medicineBulkRows.0.unit', '')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('medicines', [
        'name' => 'Paracetamol',
        'unit' => null,
    ]);
});

test('bulk create requires at least one filled medicine row', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'medicines')
        ->call('create')
        ->call('save')
        ->assertHasErrors(['medicineBulkRows']);
});

test('authenticated admins can create a drip base', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'dripBases')
        ->call('create')
        ->set('dripBaseName', 'Normal Saline')
        ->set('dripBaseDefaultVolumeMl', '100')
        ->set('dripBaseIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('drip_bases', [
        'name' => 'Normal Saline',
        'default_volume_ml' => 100,
        'is_active' => true,
    ]);
});

test('authenticated users can create a service with needs medication', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->set('serviceName', 'Consultation With Medication')
        ->set('serviceIsStandalone', false)
        ->set('serviceNeedsMedication', true)
        ->set('serviceTokenResetType', TokenResetType::Shift->value)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'name' => 'Consultation With Medication',
        'needs_medication' => true,
        'token_reset_type' => TokenResetType::Shift->value,
    ]);
});

test('catalog models can be created via factories', function () {
    $medicine = Medicine::factory()->create(['name' => 'Amoxicillin']);
    $injection = Injection::factory()->create(['name' => 'Ceftriaxone']);
    $dripBase = DripBase::factory()->create(['name' => 'Ringer Lactate']);
    $symptom = Symptom::factory()->create(['name' => 'Pain']);

    expect($medicine->is_active)->toBeTrue()
        ->and($injection->is_active)->toBeTrue()
        ->and($dripBase->is_active)->toBeTrue()
        ->and($symptom->is_active)->toBeTrue();
});

test('authenticated admins can create a symptom with medicine mappings', function () {
    $user = User::factory()->admin()->create();
    $paracetamol = Medicine::factory()->create(['name' => 'Paracetamol']);
    $ibuprofen = Medicine::factory()->create(['name' => 'Ibuprofen']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'symptoms')
        ->call('create')
        ->set('symptomName', 'Pain')
        ->set('symptomIsActive', true)
        ->set('symptomMedicineIds', [$paracetamol->id, $ibuprofen->id])
        ->call('save')
        ->assertHasNoErrors();

    $symptom = Symptom::query()->where('name', 'Pain')->first();

    expect($symptom)->not->toBeNull()
        ->and($symptom->medicines->pluck('id')->sort()->values()->all())
        ->toBe(collect([$paracetamol->id, $ibuprofen->id])->sort()->values()->all());
});

test('authenticated admins can update symptom medicine mappings', function () {
    $user = User::factory()->admin()->create();
    $paracetamol = Medicine::factory()->create(['name' => 'Paracetamol']);
    $ibuprofen = Medicine::factory()->create(['name' => 'Ibuprofen']);
    $symptom = Symptom::factory()->create(['name' => 'Fever']);
    $symptom->medicines()->attach($paracetamol);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'symptoms')
        ->call('edit', $symptom->id)
        ->assertSet('symptomName', 'Fever')
        ->assertSet('symptomMedicineIds', [$paracetamol->id])
        ->set('symptomMedicineIds', [$ibuprofen->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($symptom->fresh()->medicines->pluck('id')->all())->toBe([$ibuprofen->id]);
});

test('symptom names must be unique', function () {
    $user = User::factory()->admin()->create();
    Symptom::factory()->create(['name' => 'Pain']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'symptoms')
        ->call('create')
        ->set('symptomName', 'Pain')
        ->call('save')
        ->assertHasErrors(['symptomName']);
});

test('inactive symptoms can still be managed in the catalog', function () {
    $user = User::factory()->admin()->create();
    $symptom = Symptom::factory()->inactive()->create(['name' => 'Cough']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'symptoms')
        ->assertSee('Cough')
        ->call('edit', $symptom->id)
        ->set('symptomIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($symptom->fresh()->is_active)->toBeTrue();
});
