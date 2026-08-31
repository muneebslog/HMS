<?php

use App\Enums\LabApiStatus;
use App\Jobs\SendLabCaseToLab;
use App\Models\LabApiLog;
use App\Models\LabInvoice;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
    Config::set('services.lab.url', 'https://lab.mohsinmedicalcomplex.com');
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('lab-entries'));

    $response->assertRedirect(route('login'));
});

test('admins can visit the lab entries page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('lab-entries'));

    $response->assertOk();
});

test('management can visit the lab entries page', function () {
    $management = User::factory()->management()->create();

    $response = $this->actingAs($management)->get(route('lab-entries'));

    $response->assertOk();
});

test('receptionists can visit the lab entries page', function () {
    $receptionist = User::factory()->receptionist()->create();

    $response = $this->actingAs($receptionist)->get(route('lab-entries'));

    $response->assertOk();
});

test('doctors cannot visit the lab entries page', function () {
    $doctor = User::factory()->doctor()->create();

    $response = $this->actingAs($doctor)->get(route('lab-entries'));

    $response->assertForbidden();
});

test('users with the default user role are redirected to the pending role page', function () {
    $user = User::factory()->user()->create();

    $response = $this->actingAs($user)->get(route('lab-entries'));

    $response->assertRedirect(route('pending-role'));
});

test('lab entries page lists invoices and their api statuses', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->withPhone('03001234567')->create();
    $invoice = LabInvoice::factory()->paid()->create(['patient_id' => $patient->id]);
    LabApiLog::factory()->sent()->create([
        'lab_invoice_id' => $invoice->id,
        'lab_case_url' => 'https://lab.mohsinmedicalcomplex.com/my-visit/'.$invoice->invoice_number,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.lab-entries')
        ->assertSee($invoice->invoice_number)
        ->assertSee($invoice->patient->name)
        ->assertSee($patient->contactPhone())
        ->assertSee(number_format($invoice->total, 2))
        ->assertSee('Sent')
        ->assertSee('https://lab.mohsinmedicalcomplex.com/my-visit/'.$invoice->invoice_number);
});

test('lab entries page filters by api status', function () {
    $admin = User::factory()->admin()->create();
    $sentInvoice = LabInvoice::factory()->paid()->create();
    LabApiLog::factory()->sent()->create(['lab_invoice_id' => $sentInvoice->id]);

    $failedInvoice = LabInvoice::factory()->paid()->create();
    LabApiLog::factory()->failed()->create(['lab_invoice_id' => $failedInvoice->id]);

    Livewire::actingAs($admin)
        ->test('pages::admin.lab-entries')
        ->set('statusFilter', LabApiStatus::Sent->value)
        ->assertSee($sentInvoice->invoice_number)
        ->assertDontSee($failedInvoice->invoice_number)
        ->set('statusFilter', LabApiStatus::Failed->value)
        ->assertSee($failedInvoice->invoice_number)
        ->assertDontSee($sentInvoice->invoice_number);
});

test('lab entries page filters by invoice number', function () {
    $admin = User::factory()->admin()->create();
    $matchingInvoice = LabInvoice::factory()->paid()->create();
    $otherInvoice = LabInvoice::factory()->paid()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.lab-entries')
        ->set('keyword', $matchingInvoice->invoice_number)
        ->assertSee($matchingInvoice->invoice_number)
        ->assertDontSee($otherInvoice->invoice_number);
});

test('lab entries page filters by patient name', function () {
    $admin = User::factory()->admin()->create();
    $matchingInvoice = LabInvoice::factory()->paid()->create();
    $otherInvoice = LabInvoice::factory()->paid()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.lab-entries')
        ->set('keyword', $matchingInvoice->patient->name)
        ->assertSee($matchingInvoice->patient->name)
        ->assertDontSee($otherInvoice->invoice_number);
});

test('lab entries page filters by patient phone', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->withPhone('03001234567')->create();
    $matchingInvoice = LabInvoice::factory()->paid()->create(['patient_id' => $patient->id]);
    $otherInvoice = LabInvoice::factory()->paid()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.lab-entries')
        ->set('keyword', $patient->contactPhone())
        ->assertSee($patient->contactPhone())
        ->assertDontSee($otherInvoice->invoice_number);
});

test('admin can view lab entry details', function () {
    $admin = User::factory()->admin()->create();
    $invoice = LabInvoice::factory()->paid()->create();
    LabApiLog::factory()->sent()->create([
        'lab_invoice_id' => $invoice->id,
        'request_payload' => ['invoice_number' => $invoice->invoice_number],
        'response_body' => '{"message":"Created"}',
        'lab_case_url' => 'https://lab.mohsinmedicalcomplex.com/my-visit/'.$invoice->invoice_number,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.lab-entries')
        ->call('viewInvoice', $invoice->id)
        ->assertSet('selectedInvoiceId', $invoice->id)
        ->assertSee($invoice->invoice_number)
        ->assertSee($invoice->patient->name)
        ->assertSee('Created')
        ->assertSee('Open in lab app');
});

test('admin can retry a failed lab case sync', function () {
    Bus::fake();

    $admin = User::factory()->admin()->create();
    $invoice = LabInvoice::factory()->paid()->create();
    LabApiLog::factory()->failed()->create([
        'lab_invoice_id' => $invoice->id,
        'error_message' => 'cURL error 28: Resolving timed out',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.lab-entries')
        ->call('retryLabCase', $invoice->id)
        ->assertSee('Retry');

    Bus::assertDispatched(SendLabCaseToLab::class, fn (SendLabCaseToLab $job) => $job->labInvoiceId === $invoice->id);
});

test('retry is rejected when lab case sync is not failed', function () {
    Bus::fake();

    $admin = User::factory()->admin()->create();
    $invoice = LabInvoice::factory()->paid()->create();
    LabApiLog::factory()->sent()->create(['lab_invoice_id' => $invoice->id]);

    Livewire::actingAs($admin)
        ->test('pages::admin.lab-entries')
        ->call('retryLabCase', $invoice->id);

    Bus::assertNotDispatched(SendLabCaseToLab::class);
});
