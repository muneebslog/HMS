<?php

use App\Enums\LabResultsStatus;
use App\Enums\OutgoingSampleStatus;
use App\Jobs\SendLabCaseToLab;
use App\Jobs\SyncLabCaseStatus;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
    Config::set('services.lab.url', 'https://lab.example.test');
    Config::set('services.lab.token', 'test-token');
    Config::set('services.lab.enabled', true);
});

test('receptionists can visit the lab tracking page', function () {
    $receptionist = User::factory()->receptionist()->create();

    $this->actingAs($receptionist)
        ->get(route('reception.lab-tracking'))
        ->assertOk();
});

test('creating a lab invoice initializes outgoing samples as pending', function () {
    Bus::fake([SendLabCaseToLab::class]);

    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();
    $outgoing = LabTest::factory()->create([
        'is_in_house' => false,
        'test_name' => 'Culture',
        'test_price' => 500,
    ]);
    $inHouse = LabTest::factory()->create([
        'is_in_house' => true,
        'test_code' => '1300',
        'test_name' => 'CBC',
        'test_price' => 800,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'Track Patient')
        ->set('patientPhone', '03001112233')
        ->set('patientGender', 'male')
        ->set('patientAge', 32)
        ->set('selectedLabTestId', $outgoing->id)
        ->call('add')
        ->set('selectedLabTestId', $inHouse->id)
        ->call('add')
        ->call('save')
        ->assertHasNoErrors();

    $invoice = LabInvoice::query()->latest('id')->first();

    expect($invoice)->not->toBeNull();
    expect($invoice->items->firstWhere('is_in_house', false)?->outgoing_status)
        ->toBe(OutgoingSampleStatus::Pending);
    expect($invoice->items->firstWhere('is_in_house', true)?->outgoing_status)
        ->toBeNull();
});

test('lab tracking lists outgoing slips and advances sample status', function () {
    $user = User::factory()->receptionist()->create();
    $patient = Patient::factory()->withPhone('03001234567')->create(['name' => 'Outgoing Patient']);
    $invoice = LabInvoice::factory()->paid()->create([
        'patient_id' => $patient->id,
        'invoice_number' => '928300',
    ]);
    $item = LabInvoiceItem::factory()->outgoing()->create([
        'lab_invoice_id' => $invoice->id,
        'test_name' => 'Thyroid Panel',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-tracking')
        ->assertSee('928300')
        ->assertSee('Outgoing Patient')
        ->assertSee('Thyroid Panel')
        ->call('advanceOutgoing', $item->id)
        ->assertHasNoErrors();

    expect($item->fresh()->outgoing_status)->toBe(OutgoingSampleStatus::Asked)
        ->and($item->fresh()->asked_by)->toBe($user->id);
});

test('lab tracking can refresh in-house status and show public reports link', function () {
    Bus::fake([SyncLabCaseStatus::class]);

    $user = User::factory()->receptionist()->create();
    $invoice = LabInvoice::factory()->paid()->create([
        'invoice_number' => '928400',
        'lab_results_status' => LabResultsStatus::Pending,
    ]);
    LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $invoice->id,
        'test_code' => '1300',
        'test_name' => 'CBC',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-tracking')
        ->set('filter', 'in_house')
        ->assertSee('928400')
        ->assertSee(__('Show reports'))
        ->call('refreshStatus', $invoice->id)
        ->assertHasNoErrors();

    Bus::assertDispatched(SyncLabCaseStatus::class, fn (SyncLabCaseStatus $job) => $job->labInvoiceId === $invoice->id);
});

test('outgoing report pdf can be viewed after upload', function () {
    Storage::fake('local');

    $user = User::factory()->receptionist()->create();
    $item = LabInvoiceItem::factory()->outgoing()->create([
        'outgoing_status' => OutgoingSampleStatus::Received,
        'received_at' => now(),
        'received_by' => $user->id,
    ]);

    $path = UploadedFile::fake()->create('report.pdf', 50, 'application/pdf')
        ->storeAs('lab-reports/'.$item->lab_invoice_id, 'report.pdf', 'local');

    $item->update([
        'report_path' => $path,
        'report_original_name' => 'report.pdf',
        'report_uploaded_at' => now(),
        'report_uploaded_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('reception.lab-tracking.report', $item))
        ->assertOk();
});
