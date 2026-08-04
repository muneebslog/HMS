<?php

use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabDoctorShare;
use App\Models\LabInvoice;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('management can create a lab doctor share', function () {
    $user = User::factory()->admin()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labDoctorShares')
        ->call('create')
        ->set('labShareDoctorId', $doctor->id)
        ->set('labSharePercent', '15.50')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_doctor_shares', [
        'doctor_id' => $doctor->id,
        'share_percent' => 15.50,
    ]);
});

test('lab doctor share must be unique per doctor', function () {
    $user = User::factory()->admin()->create();
    $doctor = Doctor::factory()->create();
    LabDoctorShare::factory()->create([
        'doctor_id' => $doctor->id,
        'share_percent' => 10,
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labDoctorShares')
        ->call('create')
        ->set('labShareDoctorId', $doctor->id)
        ->set('labSharePercent', '20')
        ->call('save')
        ->assertHasErrors(['labShareDoctorId']);
});

test('management can update and delete a lab doctor share', function () {
    $user = User::factory()->admin()->create();
    $doctor = Doctor::factory()->create();
    $share = LabDoctorShare::factory()->create([
        'doctor_id' => $doctor->id,
        'share_percent' => 10,
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labDoctorShares')
        ->call('edit', $share->id)
        ->set('labSharePercent', '25')
        ->call('save')
        ->assertHasNoErrors();

    expect($share->fresh()->share_percent)->toBe(25.0);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labDoctorShares')
        ->call('delete', $share->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('lab_doctor_shares', ['id' => $share->id]);
});

test('lab entry defaults to hospital with no doctor share', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $labTest = LabTest::factory()->create(['test_price' => 1000.00]);
    $doctor = Doctor::factory()->create();
    LabDoctorShare::factory()->create([
        'doctor_id' => $doctor->id,
        'share_percent' => 20,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->call('save')
        ->assertHasNoErrors();

    $invoice = LabInvoice::first();

    expect($invoice)
        ->referred_by_doctor_id->toBeNull()
        ->doctor_share->toBeNull();
});

test('lab entry snapshots referring doctor share on discounted total', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $labTest = LabTest::factory()->create(['test_price' => 1000.00]);
    $doctor = Doctor::factory()->create();
    LabDoctorShare::factory()->create([
        'doctor_id' => $doctor->id,
        'share_percent' => 20,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->set('discountPercentage', '10')
        ->set('referredByDoctorId', $doctor->id)
        ->assertSet('doctorShareAmount', 180.00)
        ->call('save')
        ->assertHasNoErrors();

    $invoice = LabInvoice::first();

    expect($invoice)
        ->referred_by_doctor_id->toBe($doctor->id)
        ->doctor_share->toBe(20.0)
        ->total->toBe(900.0)
        ->and($invoice->doctorShareAmount())->toBe(180.0);
});

test('lab entry only lists doctors with a lab share', function () {
    $user = User::factory()->create();
    $withShare = Doctor::factory()->create(['name' => 'Dr. Share']);
    $withoutShare = Doctor::factory()->create(['name' => 'Dr. None']);
    LabDoctorShare::factory()->create([
        'doctor_id' => $withShare->id,
        'share_percent' => 15,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->assertSet('referringDoctors', function ($doctors) use ($withShare, $withoutShare) {
            return $doctors->contains('id', $withShare->id)
                && ! $doctors->contains('id', $withoutShare->id);
        });
});

test('doctor portal includes lab referral share in totals and activity', function () {
    $doctorUser = User::factory()->doctor()->create();
    $doctor = Doctor::factory()->forUser($doctorUser)->create();
    $patient = Patient::factory()->create(['name' => 'Lab Patient']);

    LabInvoice::factory()->paid()->create([
        'patient_id' => $patient->id,
        'subtotal' => 1000,
        'discount_percentage' => 10,
        'discount_amount' => 100,
        'total' => 900,
        'referred_by_doctor_id' => $doctor->id,
        'doctor_share' => 20,
        'created_at' => now(),
    ]);

    $service = Service::factory()->create(['name' => 'Consultation']);
    $invoice = Invoice::factory()->create(['patient_id' => $patient->id]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'doctor_id' => $doctor->id,
        'service_id' => $service->id,
        'service_name' => $service->name,
        'price' => 100.00,
        'doctor_share' => 50.00,
        'created_at' => now(),
    ]);

    Livewire::actingAs($doctorUser)
        ->test('pages::doctor.portal')
        ->assertSet('totalShare', 230.0)
        ->assertSet('servicesPerformed', 2)
        ->assertSee('Lab Patient')
        ->assertSee(__('Lab'));
});

test('doctor payout includes lab referral share when marking paid', function () {
    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();
    $doctor = Doctor::factory()->create();

    LabInvoice::factory()->paid()->create([
        'subtotal' => 1000,
        'discount_percentage' => 0,
        'discount_amount' => 0,
        'total' => 1000,
        'referred_by_doctor_id' => $doctor->id,
        'doctor_share' => 25,
        'created_at' => now(),
    ]);

    $fromDate = now()->toDateString();
    $toDate = now()->addDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::payout.doctor')
        ->set('fromDate', $fromDate)
        ->set('toDate', $toDate)
        ->call('viewDoctor', $doctor->id)
        ->assertSet('shareAmount', 250.0)
        ->assertSet('totalAmount', 1000.0)
        ->call('markPaid')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctor_payouts', [
        'doctor_id' => $doctor->id,
        'total_amount' => 1000.00,
        'share_amount' => 250.00,
        'created_by' => $user->id,
    ]);
});
