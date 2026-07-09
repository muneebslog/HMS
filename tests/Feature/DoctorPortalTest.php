<?php

use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\DoctorPayout;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('doctor.portal'));

    $response->assertRedirect(route('login'));
});

test('doctors can visit the portal', function () {
    $user = User::factory()->doctor()->create();
    Doctor::factory()->forUser($user)->create();

    $response = $this->actingAs($user)->get(route('doctor.portal'));

    $response->assertOk();
});

test('receptionists and management cannot visit the portal', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $response = $this->actingAs($user)->get(route('doctor.portal'));

    $response->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
]);

test('admins can visit the portal', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->get(route('doctor.portal'));

    $response->assertOk();
});

test('unassigned users are redirected to the pending role page', function () {
    $user = User::factory()->user()->create();

    $response = $this->actingAs($user)->get(route('doctor.portal'));

    $response->assertRedirect(route('pending-role'));
});

test('portal shows only the logged-in doctors stats', function () {
    $doctorUser = User::factory()->doctor()->create();
    $doctor = Doctor::factory()->forUser($doctorUser)->create();

    $otherDoctorUser = User::factory()->doctor()->create();
    $otherDoctor = Doctor::factory()->forUser($otherDoctorUser)->create();

    $patient = Patient::factory()->create();
    $service = Service::factory()->create();

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

    $otherInvoice = Invoice::factory()->create(['patient_id' => $patient->id]);
    InvoiceItem::factory()->create([
        'invoice_id' => $otherInvoice->id,
        'doctor_id' => $otherDoctor->id,
        'service_id' => $service->id,
        'service_name' => $service->name,
        'price' => 200.00,
        'doctor_share' => 50.00,
        'created_at' => now(),
    ]);

    Livewire::actingAs($doctorUser)
        ->test('pages::doctor.portal')
        ->assertSet('servicesPerformed', 1)
        ->assertSet('patientsChecked', 1)
        ->assertSet('totalShare', 50.0);
});

test('portal calculates paid and pending share correctly', function () {
    $doctorUser = User::factory()->doctor()->create();
    $doctor = Doctor::factory()->forUser($doctorUser)->create();

    $patient = Patient::factory()->create();
    $service = Service::factory()->create();

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

    DoctorPayout::factory()->create([
        'doctor_id' => $doctor->id,
        'date' => now(),
        'from_date' => now()->startOfMonth(),
        'to_date' => now()->endOfMonth(),
        'share_amount' => 30.00,
    ]);

    Livewire::actingAs($doctorUser)
        ->test('pages::doctor.portal')
        ->assertSet('totalShare', 50.0)
        ->assertSet('paidShare', 30.0)
        ->assertSet('pendingShare', 20.0);
});

test('portal renders payout with missing date range', function () {
    $doctorUser = User::factory()->doctor()->create();
    $doctor = Doctor::factory()->forUser($doctorUser)->create();

    DoctorPayout::factory()->create([
        'doctor_id' => $doctor->id,
        'date' => now(),
        'from_date' => null,
        'to_date' => null,
        'share_amount' => 30.00,
    ]);

    Livewire::actingAs($doctorUser)
        ->test('pages::doctor.portal')
        ->assertOk()
        ->assertSee(number_format(30.00, 2));
});
