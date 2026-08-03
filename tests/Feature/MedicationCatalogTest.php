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
        ->set('medicineName', 'Paracetamol')
        ->set('medicineUnit', 'tablet')
        ->set('medicineIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('medicines', [
        'name' => 'Paracetamol',
        'unit' => 'tablet',
        'is_active' => true,
    ]);
});

test('authenticated admins can create an injection', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'injections')
        ->call('create')
        ->set('injectionName', 'Diclofenac')
        ->set('injectionDefaultVolumeMl', '3')
        ->set('injectionIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('injections', [
        'name' => 'Diclofenac',
        'default_volume_ml' => 3,
        'is_active' => true,
    ]);
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
