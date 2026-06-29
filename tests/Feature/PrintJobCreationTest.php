<?php

use App\Actions\CreatePrintJob;
use App\Enums\PrintJobStatus;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\LabTest;
use App\Models\PrintJob;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.print_agent.token', 'test-agent-token');
});

test('saving a walk-in invoice creates a pending print job', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 75.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $invoice = Invoice::first();
    expect(PrintJob::count())->toBe(1);
    expect(PrintJob::first())
        ->invoice_id->toBe($invoice->id)
        ->status->toBe(PrintJobStatus::Pending)
        ->payload->toMatchArray(['type' => 'invoice', 'source' => 'web']);
});

test('saving a lab invoice creates a pending print job', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $test = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'Jane Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'female')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $test->id)
        ->call('add')
        ->call('save')
        ->assertHasNoErrors();

    expect(PrintJob::count())->toBe(1);
    expect(PrintJob::first())
        ->lab_invoice_id->not->toBeNull()
        ->status->toBe(PrintJobStatus::Pending)
        ->payload->toMatchArray(['type' => 'lab_invoice', 'source' => 'web']);
});

test('the invoices page can queue a print job for a walk-in invoice', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $invoice = Invoice::factory()->create(['shift_id' => $shift->id]);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->call('printInvoice', $invoice->id, 'walkin')
        ->assertHasNoErrors();

    expect(PrintJob::count())->toBe(1);
    expect(PrintJob::first())
        ->invoice_id->toBe($invoice->id)
        ->status->toBe(PrintJobStatus::Pending);
});

test('the invoices page can queue a print job for a lab invoice', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $labInvoice = LabInvoice::factory()->create(['shift_id' => $shift->id]);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->call('printInvoice', $labInvoice->id, 'lab')
        ->assertHasNoErrors();

    expect(PrintJob::count())->toBe(1);
    expect(PrintJob::first())
        ->lab_invoice_id->toBe($labInvoice->id)
        ->status->toBe(PrintJobStatus::Pending);
});

test('createForShift creates a pending shift report print job', function () {
    $shift = Shift::factory()->for(User::factory()->receptionist())->open()->create();

    $job = app(CreatePrintJob::class)->createForShift($shift);

    expect(PrintJob::count())->toBe(1);
    expect($job)
        ->shift_id->toBe($shift->id)
        ->status->toBe(PrintJobStatus::Pending);
    expect($job->payload)->toMatchArray(['type' => 'shift_report', 'source' => 'web']);
});

test('a print job can be retried from the monitoring page', function () {
    $user = User::factory()->create();
    $job = PrintJob::factory()->failed()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.print-jobs')
        ->call('retry', $job->id)
        ->assertHasNoErrors();

    expect($job->fresh())
        ->status->toBe(PrintJobStatus::Pending)
        ->failed_at->toBeNull()
        ->error_message->toBeNull();
});
