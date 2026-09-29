<?php

use App\Enums\MedicalCertificateType;
use App\Models\Doctor;
use App\Models\MedicalCertificate;
use App\Models\MedicationOrder;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
    $this->receptionist = User::factory()->receptionist()->create();
});

test('reception, management and doctors can open the medical certificates page', function (string $role) {
    $this->actingAs(User::factory()->{$role}()->create())
        ->get(route('reception.medical-certificates'))
        ->assertOk()
        ->assertSee('Medical Certificates')
        ->assertSee(route('reception.medical-certificates'), false);
})->with(['receptionist', 'management', 'doctor']);

test('roles without the page cannot open it', function () {
    $this->actingAs(User::factory()->labTechnician()->create())
        ->get(route('reception.medical-certificates'))
        ->assertForbidden();
});

test('choosing a patient fills the form from their record', function () {
    $doctor = Doctor::factory()->create(['name' => 'Sana Ahmed']);
    $patient = Patient::factory()->create([
        'name' => 'Muhammad Usman',
        'husband_name' => 'Imran Ali',
        'gender' => 'male',
        'age' => 16,
        'cnic' => '35202-1234567-8',
    ]);
    MedicationOrder::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'complaint_or_diagnosis' => 'Enteric Fever',
    ]);

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.medical-certificates')
        ->call('create')
        ->set('patientSearch', 'usman')
        ->assertSee('MUHAMMAD USMAN')
        ->call('selectPatient', $patient->id)
        ->assertSet('patientId', $patient->id)
        ->assertSet('patientTitle', 'Mr.')
        ->assertSet('patientName', 'MUHAMMAD USMAN')
        ->assertSet('relation', 's/o')
        ->assertSet('guardianName', 'IMRAN ALI')
        ->assertSet('age', 16)
        ->assertSet('mrn', $patient->mrn)
        ->assertSet('cnic', '35202-1234567-8')
        ->assertSet('diagnosis', 'Enteric Fever')
        ->assertSet('doctorId', $doctor->id)
        ->assertSet('doctorName', 'Dr. Sana Ahmed');
});

test('a married female patient is written as mrs with w/o', function () {
    $patient = Patient::factory()->create(['gender' => 'female', 'husband_name' => 'Imran Khan', 'age' => 28]);

    expect(MedicalCertificate::detailsFromPatient($patient))
        ->patient_title->toBe('Mrs.')
        ->relation->toBe('w/o')
        ->gender->toBe('female');
});

test('changing the title updates the pronouns and relation', function () {
    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.medical-certificates')
        ->call('create')
        ->set('patientTitle', 'Miss')
        ->assertSet('gender', 'female')
        ->assertSet('relation', 'd/o');
});

test('the details can be edited before issuing and the certificate opens for printing', function () {
    $patient = Patient::factory()->create(['name' => 'Muhammad Usman', 'gender' => 'male', 'age' => 16]);

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.medical-certificates')
        ->call('create')
        ->call('selectPatient', $patient->id)
        ->set('patientName', 'Muhammad Usman Khan')
        ->set('guardianName', 'Imran Ali')
        ->set('age', 17)
        ->set('diagnosis', 'Enteric Fever')
        ->set('startDate', '2025-12-13')
        ->set('endDate', '2025-12-15')
        ->set('doctorName', 'Dr. Sana Ahmed')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $certificate = MedicalCertificate::query()->sole();

    expect($certificate)
        ->type->toBe(MedicalCertificateType::SickLeave)
        ->patient_id->toBe($patient->id)
        ->patient_name->toBe('Muhammad Usman Khan')
        ->guardian_name->toBe('Imran Ali')
        ->age->toBe(17)
        ->issued_by->toBe($this->receptionist->id)
        ->serial_no->toBe('MC-'.now()->format('Y').'-'.str_pad((string) $certificate->id, 6, '0', STR_PAD_LEFT))
        ->and($certificate->end_date->toDateString())->toBe('2025-12-15')
        ->and($certificate->totalDays())->toBe(3);
});

test('a sick leave certificate needs a name, a diagnosis and a to date', function () {
    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.medical-certificates')
        ->call('create')
        ->set('endDate', '')
        ->set('doctorName', 'Dr. Sana Ahmed')
        ->call('save')
        ->assertHasErrors(['patientName', 'diagnosis', 'endDate']);

    expect(MedicalCertificate::query()->count())->toBe(0);
});

test('the to date cannot be before the from date', function () {
    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.medical-certificates')
        ->call('create')
        ->set('patientName', 'Test Patient')
        ->set('diagnosis', 'Fever')
        ->set('startDate', '2025-12-15')
        ->set('endDate', '2025-12-13')
        ->set('doctorName', 'Dr. Sana Ahmed')
        ->call('save')
        ->assertHasErrors(['endDate']);
});

test('the diagnosis can be left off the certificate', function () {
    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.medical-certificates')
        ->call('create')
        ->set('patientName', 'Test Patient')
        ->set('showDiagnosis', false)
        ->set('doctorName', 'Dr. Sana Ahmed')
        ->call('save')
        ->assertHasNoErrors();

    expect(MedicalCertificate::query()->sole()->show_diagnosis)->toBeFalse();
});

test('an issued certificate can be corrected', function () {
    $certificate = MedicalCertificate::factory()->create(['end_date' => today()->addDays(2)]);

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.medical-certificates')
        ->call('edit', $certificate->id)
        ->assertSet('patientName', $certificate->patient_name)
        ->set('endDate', today()->addDays(4)->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    expect($certificate->fresh()->end_date->toDateString())->toBe(today()->addDays(4)->toDateString())
        ->and(MedicalCertificate::query()->count())->toBe(1);
});

test('the list can be searched by name or certificate number', function () {
    MedicalCertificate::factory()->create(['patient_name' => 'Muhammad Usman']);
    MedicalCertificate::factory()->create(['patient_name' => 'Ayesha Khan']);

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.medical-certificates')
        ->set('search', 'usman')
        ->assertSee('Muhammad Usman')
        ->assertDontSee('Ayesha Khan');
});

test('the printed certificate reads as a sentence and counts prints', function () {
    $certificate = MedicalCertificate::factory()->create([
        'patient_title' => 'Mr.',
        'patient_name' => 'Muhammad Usman',
        'relation' => 's/o',
        'guardian_name' => 'Imran Ali',
        'age' => 16,
        'diagnosis' => 'Enteric Fever',
        'start_date' => '2025-12-13',
        'end_date' => '2025-12-15',
        'doctor_name' => 'Dr. Sana Ahmed',
    ]);

    $this->actingAs($this->receptionist)
        ->get(route('reception.medical-certificates.print', $certificate))
        ->assertOk()
        ->assertSeeInOrder(['This is to certify that', 'Mr.', 'Muhammad Usman', 's/o', 'Imran Ali', '16', 'is suffering from', 'Enteric Fever', 'I advise him complete bed rest from', '13-12-2025', 'to', '15-12-2025'])
        ->assertSee($certificate->serial_no)
        ->assertSee('Dr. Sana Ahmed')
        ->assertSee($certificate->verificationUrl());

    expect($certificate->fresh()->print_count)->toBe(1);
});

test('a hidden diagnosis is not printed', function () {
    $certificate = MedicalCertificate::factory()->create(['diagnosis' => 'Enteric Fever', 'show_diagnosis' => false]);

    $this->actingAs($this->receptionist)
        ->get(route('reception.medical-certificates.print', $certificate))
        ->assertOk()
        ->assertDontSee('Enteric Fever')
        ->assertSee('is not feeling well.');
});

test('each certificate type prints its own wording', function (MedicalCertificateType $type, array $attributes, string $expected) {
    $certificate = MedicalCertificate::factory()->female()->create(['type' => $type, ...$attributes]);

    $this->actingAs($this->receptionist)
        ->get(route('reception.medical-certificates.print', $certificate))
        ->assertOk()
        ->assertSee($type->title())
        ->assertSee($expected);
})->with([
    'fitness' => [MedicalCertificateType::Fitness, ['resume_date' => '2025-12-16'], 'She has now recovered and is medically fit to resume her normal duties / studies from'],
    'maternity' => [MedicalCertificateType::Maternity, ['gestation_weeks' => 36, 'expected_delivery_date' => '2026-10-22'], 'I advise her maternity leave from'],
    'attendance' => [MedicalCertificateType::Attendance, ['end_date' => null, 'time_from' => '10:15', 'time_to' => '11:40'], '10:15 AM'],
]);

test('a certificate can be voided with a reason and then no longer prints', function () {
    $certificate = MedicalCertificate::factory()->create();

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.medical-certificates')
        ->call('confirmVoid', $certificate->id)
        ->call('voidCertificate')
        ->assertHasErrors(['voidReason'])
        ->set('voidReason', 'Wrong dates')
        ->call('voidCertificate')
        ->assertHasNoErrors();

    expect($certificate->fresh())
        ->isVoided()->toBeTrue()
        ->void_reason->toBe('Wrong dates')
        ->voided_by->toBe($this->receptionist->id);

    $this->actingAs($this->receptionist)
        ->get(route('reception.medical-certificates.print', $certificate))
        ->assertNotFound();
});

test('anyone can verify a certificate by scanning its qr code', function () {
    $certificate = MedicalCertificate::factory()->create(['patient_name' => 'Muhammad Usman']);

    $this->get(route('medical-certificates.verify', $certificate->verification_token))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('This certificate is genuine and valid.')
        ->assertSee($certificate->serial_no)
        ->assertSee('MUHAMMAD USMAN');
});

test('a voided certificate shows as cancelled when verified', function () {
    $certificate = MedicalCertificate::factory()->voided()->create();

    $this->get(route('medical-certificates.verify', $certificate->verification_token))
        ->assertOk()
        ->assertSee('This certificate has been cancelled by the hospital.');
});

test('an unknown verification code is not found', function () {
    $this->get(route('medical-certificates.verify', 'not-a-real-token'))->assertNotFound();
});
