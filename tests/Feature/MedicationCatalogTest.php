<?php

use App\Enums\TokenResetType;
use App\Models\DripBase;
use App\Models\Injection;
use App\Models\Medicine;
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
        ->set('medicineBulkRows.0.is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('medicines', [
        'name' => 'Paracetamol',
        'short_form' => 'PCM',
        'unit' => 'tablet',
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
        ->set('injectionBulkRows.0.default_volume_ml', '3')
        ->set('injectionBulkRows.0.is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('injections', [
        'name' => 'Diclofenac',
        'short_form' => 'DIC',
        'default_volume_ml' => 3,
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
        ->set('injectionBulkRows.0.default_volume_ml', '3')
        ->set('injectionBulkRows.1.name', 'Ceftriaxone')
        ->set('injectionBulkRows.1.default_volume_ml', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Injection::query()->count())->toBe(2);

    $this->assertDatabaseHas('injections', [
        'name' => 'Diclofenac',
        'short_form' => 'DIC',
        'default_volume_ml' => 3,
    ]);

    $this->assertDatabaseHas('injections', [
        'name' => 'Ceftriaxone',
        'default_volume_ml' => null,
    ]);
});

test('bulk medicine create requires a unit when a name is provided', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'medicines')
        ->call('create')
        ->set('medicineBulkRows.0.name', 'Paracetamol')
        ->set('medicineBulkRows.0.unit', '')
        ->call('save')
        ->assertHasErrors(['medicineBulkRows.0.unit']);
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

    expect($medicine->is_active)->toBeTrue()
        ->and($injection->is_active)->toBeTrue()
        ->and($dripBase->is_active)->toBeTrue();
});
