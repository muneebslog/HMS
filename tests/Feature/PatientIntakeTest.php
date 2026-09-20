<?php

use App\Enums\TokenResetType;
use App\Models\AdminNotification;
use App\Models\AppSetting;
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

test('walk-in can edit a selected patient name and phone from the modal', function () {
    $user = User::factory()->create();
    $existing = Patient::factory()->withPhone(intakePhone())->create([
        'name' => 'Original Name',
        'age' => 28,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientPhone', intakePhone())
        ->call('selectMatchedPatient', $existing->id)
        ->assertSee(__('Edit'))
        ->call('openEditPatientModal')
        ->assertSet('showEditPatientModal', true)
        ->assertSet('editPatientName', 'ORIGINAL NAME')
        ->assertSet('editPatientPhone', intakePhone())
        ->set('editPatientName', 'Corrected Name')
        ->set('editPatientPhone', '03009876543')
        ->call('saveSelectedPatientDetails')
        ->assertSet('showEditPatientModal', false)
        ->assertSet('patientName', 'CORRECTED NAME')
        ->assertSet('patientPhone', '03009876543')
        ->assertHasNoErrors();

    expect($existing->fresh())
        ->name->toBe('CORRECTED NAME')
        ->age->toBe(28)
        ->and($existing->fresh()->contactPhone())->toBe('03009876543');
});

test('walk-in edit patient modal requires a name', function () {
    $user = User::factory()->create();
    $existing = Patient::factory()->withPhone(intakePhone())->create(['name' => 'Original Name']);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientPhone', intakePhone())
        ->call('selectMatchedPatient', $existing->id)
        ->call('openEditPatientModal')
        ->set('editPatientName', '')
        ->call('saveSelectedPatientDetails')
        ->assertHasErrors(['editPatientName' => 'required'])
        ->assertSet('showEditPatientModal', true);

    expect($existing->fresh()->name)->toBe('ORIGINAL NAME');
});

test('clearing a selected patient also clears the patient name', function () {
    $user = User::factory()->create();
    $existing = Patient::factory()->withPhone(intakePhone())->create(['name' => 'Returning Patient']);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientPhone', intakePhone())
        ->call('selectMatchedPatient', $existing->id)
        ->assertSet('selectedPatientId', $existing->id)
        ->assertSet('patientName', 'RETURNING PATIENT')
        ->call('clearSelectedPatient')
        ->assertSet('selectedPatientId', null)
        ->assertSet('patientName', '');
});

test('patient names are uppercased when saved', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 100,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'john doe')
        ->assertSet('patientName', 'john doe')
        ->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    expect(Patient::first()->name)->toBe('JOHN DOE');
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

test('walk-in hides have no number and requires phone when the setting is disabled', function () {
    AppSetting::set(AppSetting::AllowHaveNoNumber, '0');

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
        ->assertDontSee(__('Have no number'))
        ->set('patientName', 'Blocked No Phone')
        ->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->assertSet('hasNoPhone', false)
        ->call('saveInvoice')
        ->assertHasErrors(['patientPhone']);

    expect(Invoice::count())->toBe(0);
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
    $newPatient = Patient::where('name', 'BROTHER')->first();
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

test('walk-in shows patient name field only when creating a new person', function () {
    $user = User::factory()->create();
    $existing = Patient::factory()->withPhone(intakePhone())->create(['name' => 'Existing']);

    $component = Livewire::actingAs($user)->test('pages::reception.walkin');

    expect($component->instance()->shouldShowPatientNameField())->toBeFalse();

    $component->set('hasNoPhone', true);
    expect($component->instance()->shouldShowPatientNameField())->toBeTrue();

    $component->set('hasNoPhone', false)->set('patientPhone', '03009998877');
    expect($component->instance()->shouldShowPatientNameField())->toBeTrue();

    $component->set('patientPhone', intakePhone())->call('selectMatchedPatient', $existing->id);
    expect($component->instance()->shouldShowPatientNameField())->toBeFalse()
        ->and($component->get('patientName'))->toBe('EXISTING');

    $component->call('addNewFamilyMember');
    expect($component->instance()->shouldShowPatientNameField())->toBeTrue()
        ->and($component->get('patientName'))->toBe('');
});

test('reservation shows patient name field only when creating a new person', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $existing = Patient::factory()->withPhone(intakePhone())->create(['name' => 'Reserved Patient']);
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

    $component = Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 5);

    expect($component->instance()->shouldShowPatientNameField())->toBeFalse();

    $component->set('patientPhone', intakePhone())->call('selectMatchedPatient', $existing->id);
    expect($component->instance()->shouldShowPatientNameField())->toBeFalse();

    $component->call('addNewFamilyMember');
    expect($component->instance()->shouldShowPatientNameField())->toBeTrue();
});

test('lab entry and procedures show patient name field only when creating a new person', function () {
    $user = User::factory()->create();
    $existing = Patient::factory()->withPhone(intakePhone())->create(['name' => 'Shared Patient']);

    $lab = Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientPhone', intakePhone())
        ->call('selectMatchedPatient', $existing->id);
    expect($lab->instance()->shouldShowPatientNameField())->toBeFalse();
    $lab->call('addNewFamilyMember');
    expect($lab->instance()->shouldShowPatientNameField())->toBeTrue();

    $procedures = Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientPhone', intakePhone())
        ->call('selectMatchedPatient', $existing->id);
    expect($procedures->instance()->shouldShowPatientNameField())->toBeFalse();
    $procedures->call('addNewFamilyMember');
    expect($procedures->instance()->shouldShowPatientNameField())->toBeTrue();
});
