<?php

use App\Enums\TokenResetType;
use App\Models\AdminNotification;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedureType;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function intakePhone(): string
{
    return '03001234567';
}

test('walk-in can select an existing patient by phone so invoices share the same mrn', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $existing = Patient::factory()->withPhone(intakePhone())->create(['name' => 'Returning Patient']);
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 100,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientPhone', intakePhone())
        ->call('selectMatchedPatient', $existing->id)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    expect(Patient::count())->toBe(1);
    expect(Invoice::first()->patient_id)->toBe($existing->id);
    expect(Invoice::first()->patient->mrn)->toBe($existing->fresh()->mrn);
});

test('walk-in without phone notifies admin', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 75,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'No Phone Walk-in')
        ->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    expect(AdminNotification::where('type', 'patient_without_phone')->count())->toBe(1);
});

test('reservation can reuse an existing patient under the same family phone', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $existing = Patient::factory()->withPhone(intakePhone())->create(['name' => 'Family Member']);
    $service = Service::factory()->create([
        'name' => 'Consultation',
        'is_standalone' => false,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $doctor = Doctor::factory()->create();
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 250,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 4)
        ->set('patientPhone', intakePhone())
        ->call('selectMatchedPatient', $existing->id)
        ->call('reserve')
        ->assertHasNoErrors();

    expect(Patient::count())->toBe(1);
    expect(QueueToken::first()->patient_id)->toBe($existing->id);
});

test('lab entry can add a new family member under an existing phone', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $familyPatient = Patient::factory()->withPhone(intakePhone())->create(['name' => 'Sister']);
    $labTest = LabTest::factory()->create(['test_price' => 500]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientPhone', intakePhone())
        ->call('addNewFamilyMember')
        ->set('patientName', 'Brother')
        ->set('patientGender', 'male')
        ->set('patientAge', 18)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->call('save')
        ->assertHasNoErrors();

    expect(Patient::count())->toBe(2);
    $newPatient = Patient::where('name', 'Brother')->first();
    expect($newPatient->family_id)->toBe($familyPatient->family_id);
    expect(LabInvoice::first()->patient_id)->toBe($newPatient->id);
});

test('procedure creation without phone notifies admin', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $procedureType = ProcedureType::factory()->create(['name' => 'Normal Delivery']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'Elderly Mother')
        ->set('hasNoPhone', true)
        ->set('husbandName', 'Late Husband')
        ->set('patientAge', 70)
        ->set('procedureTypeId', $procedureType->id)
        ->set('expectedDeliveryDate', '2026-12-15')
        ->set('fullAmount', '5000')
        ->call('saveProcedure')
        ->assertHasNoErrors();

    expect(Procedure::count())->toBe(1);
    expect(Patient::first()->contactPhone())->toBeNull();
    expect(AdminNotification::where('type', 'patient_without_phone')->count())->toBe(1);
});
