<?php

use App\Jobs\SendLabCaseToLab;
use App\Models\AdminNotification;
use App\Models\LabInvoice;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\PrintJob;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a lab invoice can be saved with items', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $labTest = LabTest::factory()->create([
        'test_name' => 'Complete Blood Count',
        'test_code' => 'CBC-001',
        'test_price' => 1200.00,
        'time_required' => '1 hour',
        'is_in_house' => true,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->call('save')
        ->assertHasNoErrors();

    $patient = Patient::where('name', 'John Doe')->first();
    expect($patient)->not->toBeNull()
        ->phone->toBe('1234567890')
        ->age->toBe(30)
        ->gender->toBe('male');

    $invoice = LabInvoice::where('patient_id', $patient->id)->first();
    expect($invoice)->not->toBeNull()
        ->subtotal->toBe(1200.00)
        ->discount_percentage->toBe(0.0)
        ->discount_amount->toBe(0.0)
        ->total->toBe(1200.00)
        ->status->toBe('paid')
        ->created_by->toBe($user->id);

    expect($invoice->items)->toHaveCount(1)
        ->and($invoice->items->first())
        ->lab_test_id->toBe($labTest->id)
        ->test_name->toBe('Complete Blood Count')
        ->test_code->toBe('CBC-001')
        ->time_required->toBe('1 hour')
        ->is_in_house->toBeTrue()
        ->price->toBe(1200.00);
});

test('a lab invoice can be saved with a discount', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $labTest = LabTest::factory()->create(['test_price' => 1000.00]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'Jane Doe')
        ->set('patientPhone', '0987654321')
        ->set('patientGender', 'female')
        ->set('patientAge', 25)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->set('discountPercentage', '10')
        ->call('save')
        ->assertHasNoErrors();

    $invoice = LabInvoice::first();
    expect($invoice)->not->toBeNull()
        ->subtotal->toBe(1000.00)
        ->discount_percentage->toBe(10.0)
        ->discount_amount->toBe(100.00)
        ->total->toBe(900.00);
});

test('saving a lab invoice requires patient details', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->set('patientName', '')
        ->set('patientPhone', '')
        ->set('patientGender', '')
        ->set('patientAge', null)
        ->call('save')
        ->assertHasErrors(['patientName', 'patientPhone', 'patientGender', 'patientAge']);

    expect(LabInvoice::count())->toBe(0);
});

test('saving a lab invoice requires at least one item', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->call('save')
        ->assertHasErrors(['items']);

    expect(LabInvoice::count())->toBe(0)
        ->and(Patient::count())->toBe(0);
});

test('saving a lab invoice clears the form', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->call('save')
        ->assertSet('patientName', '')
        ->assertSet('patientPhone', '')
        ->assertSet('patientGender', '')
        ->assertSet('patientAge', null)
        ->assertCount('items', 0);
});

test('a lab invoice can be saved when a test has no code', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $labTest = LabTest::factory()->create([
        'test_name' => 'ASO Titer',
        'test_code' => null,
        'test_price' => 1000.00,
        'time_required' => 'Next day',
        'is_in_house' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->call('save')
        ->assertHasNoErrors();

    $invoice = LabInvoice::first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->items)->toHaveCount(1)
        ->and($invoice->items->first())
        ->test_name->toBe('ASO Titer')
        ->test_code->toBeNull()
        ->price->toBe(1000.00);
});

test('saving a lab invoice uses a predictable invoice number and queues two receipts', function () {
    Bus::fake();

    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $labTest = LabTest::factory()->create([
        'test_name' => 'Complete Blood Count',
        'test_code' => '1300',
        'test_price' => 1200.00,
        'time_required' => '1 hour',
        'is_in_house' => true,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->call('save')
        ->assertHasNoErrors();

    $invoice = LabInvoice::first();
    expect($invoice)->not->toBeNull()
        ->invoice_number->toMatch('/^\d{12}$/');

    $printJobs = PrintJob::where('lab_invoice_id', $invoice->id)->get();
    expect($printJobs)->toHaveCount(2);

    $copyTypes = $printJobs->pluck('payload.copy_for')->all();
    expect($copyTypes)->toContain('patient', 'lab');

    $patientCopy = $printJobs->firstWhere('payload.copy_for', 'patient');
    expect($patientCopy->payload['qr_url'])->toBe(rtrim(config('services.lab.url'), '/').'/my-visit/'.$invoice->invoice_number);

    Bus::assertDispatched(SendLabCaseToLab::class, fn ($job) => $job->labInvoiceId === $invoice->id);
});

test('in-house tests without numeric codes create an admin notification', function () {
    config(['services.lab.url' => 'https://lab.mohsinmedicalcomplex.com']);
    config(['services.lab.token' => 'test-token']);
    config(['services.lab.enabled' => true]);

    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $labTest = LabTest::factory()->create([
        'test_name' => 'ASO Titer',
        'test_code' => 'ABC',
        'test_price' => 1000.00,
        'time_required' => 'Next day',
        'is_in_house' => true,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->call('save')
        ->assertHasNoErrors();

    $invoice = LabInvoice::first();
    expect($invoice)->not->toBeNull();

    $notification = AdminNotification::where('type', 'lab_test_missing_code')->first();
    expect($notification)->not->toBeNull()
        ->message->toContain('ASO Titer');
});
