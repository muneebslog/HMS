<?php

use App\Enums\ProcedureAttachmentType;
use App\Enums\ProcedureDocumentKind;
use App\Enums\ProcedureMedicationDoseStatus;
use App\Enums\ProcedureMedicationForm;
use App\Enums\ProcedureMedicationScheduleType;
use App\Enums\UserRole;
use App\Models\AdminNotification;
use App\Models\Doctor;
use App\Models\Medicine;
use App\Models\Procedure;
use App\Models\ProcedureAttachment;
use App\Models\ProcedureDocument;
use App\Models\ProcedureMedication;
use App\Models\ProcedurePayment;
use App\Models\ProcedureType;
use App\Models\ProcedureVital;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ProcedureMedicationScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('indoor staff can visit the ward list of admitted procedures', function () {
    $user = User::factory()->indoor()->create();
    $admitted = Procedure::factory()->admitted()->create();
    Procedure::factory()->create(['admitted_at' => null]);

    $this->actingAs($user)
        ->get(route('indoor.ward'))
        ->assertOk()
        ->assertSeeLivewire('pages::indoor.ward')
        ->assertSee($admitted->patient->name)
        ->assertSee(route('indoor.procedure', $admitted), false);
});

test('procedure chart header shows the patient balance', function () {
    $user = User::factory()->indoor()->create();
    $procedure = Procedure::factory()->admitted()->create([
        'full_amount' => 5000,
    ]);

    ProcedurePayment::factory()->create([
        'procedure_id' => $procedure->id,
        'amount' => 1500,
    ]);

    $this->actingAs($user)
        ->get(route('indoor.procedure', $procedure))
        ->assertOk()
        ->assertSee(__('Balance'))
        ->assertSee('3,500.00');
});

test('procedure chart wizard tabs switch the visible section', function () {
    $user = User::factory()->indoor()->create();
    $procedure = Procedure::factory()->admitted()->create();

    Livewire::actingAs($user)
        ->test('pages::indoor.procedure', ['procedure' => $procedure])
        ->assertSet('activeTab', 'consent')
        ->assertSee(__('Mark consent complete'))
        ->call('setActiveTab', 'vitals')
        ->assertSet('activeTab', 'vitals')
        ->assertSee(__('Record vitals'))
        ->assertDontSee(__('Mark consent complete'))
        ->call('nextTab')
        ->assertSet('activeTab', 'medications')
        ->call('previousTab')
        ->assertSet('activeTab', 'vitals');
});

test('indoor staff can open an admitted procedure chart', function () {
    $user = User::factory()->indoor()->create();
    $procedure = Procedure::factory()->admitted()->create();

    $this->actingAs($user)
        ->get(route('indoor.procedure', $procedure))
        ->assertOk()
        ->assertSeeLivewire('pages::indoor.procedure');
});

test('indoor staff can upload consent photos and mark consent complete', function () {
    Storage::fake('local');

    $user = User::factory()->indoor()->create();
    $procedure = Procedure::factory()->admitted()->create();

    Livewire::actingAs($user)
        ->test('pages::indoor.procedure', ['procedure' => $procedure])
        ->set('consentPhotos', [
            UploadedFile::fake()->image('consent-1.jpg'),
            UploadedFile::fake()->image('consent-2.jpg'),
        ])
        ->call('saveConsentPhotos')
        ->call('markConsentComplete')
        ->assertHasNoErrors();

    expect($procedure->fresh()->consent_completed_at)->not->toBeNull()
        ->and(ProcedureAttachment::where('procedure_id', $procedure->id)->where('type', ProcedureAttachmentType::Consent)->count())
        ->toBe(2);
});

test('consent cannot be marked complete with fewer than two photos', function () {
    Storage::fake('local');

    $user = User::factory()->indoor()->create();
    $procedure = Procedure::factory()->admitted()->create();

    Livewire::actingAs($user)
        ->test('pages::indoor.procedure', ['procedure' => $procedure])
        ->set('consentPhotos', [
            UploadedFile::fake()->image('consent.jpg'),
        ])
        ->call('saveConsentPhotos')
        ->call('markConsentComplete')
        ->assertSee(__('Upload at least 2 consent photos before marking consent complete.'));

    expect($procedure->fresh()->consent_completed_at)->toBeNull()
        ->and(ProcedureAttachment::where('procedure_id', $procedure->id)->where('type', ProcedureAttachmentType::Consent)->count())
        ->toBe(1);
});

test('indoor staff can record vitals for an admitted procedure', function () {
    $user = User::factory()->indoor()->create();
    $procedure = Procedure::factory()->admitted()->create();

    Livewire::actingAs($user)
        ->test('pages::indoor.procedure', ['procedure' => $procedure])
        ->set('vitalPulse', '88')
        ->set('vitalBpSystolic', '120')
        ->set('vitalBpDiastolic', '80')
        ->set('vitalRespRate', '18')
        ->set('vitalTemp', '98.6')
        ->call('saveVital')
        ->assertHasNoErrors();

    expect(ProcedureVital::where('procedure_id', $procedure->id)->count())->toBe(1);
});

test('catalog and custom medications can be prescribed and marked given', function () {
    $user = User::factory()->indoor()->create();
    $procedure = Procedure::factory()->admitted()->create();
    $medicine = Medicine::factory()->create(['name' => 'Paracetamol']);

    Livewire::actingAs($user)
        ->test('pages::indoor.procedure', ['procedure' => $procedure])
        ->set('medForm', ProcedureMedicationForm::Tab->value)
        ->set('medMedicineId', $medicine->id)
        ->set('medDose', '500mg')
        ->set('medRoute', 'oral')
        ->set('medScheduleType', ProcedureMedicationScheduleType::OnceNow->value)
        ->call('prescribeMedication')
        ->assertHasNoErrors();

    $medication = ProcedureMedication::where('procedure_id', $procedure->id)->first();
    expect($medication)->not->toBeNull()
        ->and($medication->doses()->count())->toBeGreaterThan(0);

    $dose = $medication->doses()->first();

    Livewire::actingAs($user)
        ->test('pages::indoor.procedure', ['procedure' => $procedure])
        ->call('markDoseGiven', $dose->id)
        ->assertHasNoErrors();

    expect($dose->fresh()->status)->toBe(ProcedureMedicationDoseStatus::Given)
        ->and($dose->fresh()->given_by)->toBe($user->id);

    $scheduler = app(ProcedureMedicationScheduler::class);
    $custom = ProcedureMedication::factory()->create([
        'procedure_id' => $procedure->id,
        'form' => ProcedureMedicationForm::Inj,
        'custom_name' => 'Outside Antibiotic',
        'medicine_id' => null,
        'schedule_type' => ProcedureMedicationScheduleType::OnceAt,
        'schedule_times' => ['16:00'],
        'prescribed_by' => $user->id,
    ]);
    $scheduler->materialize($custom, now()->setTime(10, 0));

    expect($custom->doses()->count())->toBe(1)
        ->and($custom->displayName())->toBe('Outside Antibiotic');
});

test('doctor portal lists their assigned ward procedures', function () {
    $doctorUser = User::factory()->doctor()->create();
    $doctor = Doctor::factory()->forUser($doctorUser)->create();
    $mine = Procedure::factory()->admitted()->create(['doctor_id' => $doctor->id]);
    Procedure::factory()->admitted()->create();

    $this->actingAs($doctorUser)
        ->get(route('doctor.procedures'))
        ->assertOk()
        ->assertSee($mine->patient->name);
});

test('discharge and birth certificates are tracked when opened', function () {
    $user = User::factory()->indoor()->create();
    $type = ProcedureType::factory()->delivery()->create();
    $procedure = Procedure::factory()->admitted()->for($type)->discharged()->create([
        'discharged_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('indoor.procedures.discharge-certificate', $procedure))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('indoor.procedures.birth-certificate', $procedure))
        ->assertOk();

    expect(ProcedureDocument::where('procedure_id', $procedure->id)->where('kind', ProcedureDocumentKind::DischargeCertificate)->exists())->toBeTrue()
        ->and(ProcedureDocument::where('procedure_id', $procedure->id)->where('kind', ProcedureDocumentKind::BirthCertificate)->exists())->toBeTrue();
});

test('missing vitals command notifies admins once per hour block', function () {
    User::factory()->admin()->create();
    $procedure = Procedure::factory()->admitted()->create([
        'admitted_at' => now()->subHours(3),
    ]);

    $this->artisan('procedures:check-missing-vitals')->assertSuccessful();

    $notifications = app(NotificationService::class);
    expect(AdminNotification::where('type', 'procedure_vitals_missing')->count())->toBe(1);

    $this->artisan('procedures:check-missing-vitals')->assertSuccessful();

    expect(AdminNotification::where('type', 'procedure_vitals_missing')->count())->toBe(1);

    ProcedureVital::factory()->create([
        'procedure_id' => $procedure->id,
        'recorded_at' => now()->subHour()->startOfHour()->addMinutes(10),
    ]);
});

test('indoor role is requestable and redirects to the ward after login response', function () {
    expect(UserRole::Indoor->label())->toBe(__('Indoor Staff'))
        ->and(User::factory()->indoor()->create()->isIndoor())->toBeTrue();
});
