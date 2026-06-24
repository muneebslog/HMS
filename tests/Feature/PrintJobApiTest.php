<?php

use App\Enums\PrintJobStatus;
use App\Models\Invoice;
use App\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.print_agent.token', 'test-agent-token');
});

function agentHeaders(): array
{
    return [
        'Authorization' => 'Bearer test-agent-token',
        'Accept' => 'application/json',
    ];
}

test('the agent can fetch pending print jobs', function () {
    $pending = PrintJob::factory()->pending()->create();
    PrintJob::factory()->printed()->create();
    PrintJob::factory()->failed()->create();

    $response = $this->getJson(route('api.print-jobs.pending'), agentHeaders());

    $response->assertOk()
        ->assertJsonPath('data.0.id', $pending->id)
        ->assertJsonPath('data.0.status', PrintJobStatus::Pending->value);
});

test('the agent cannot fetch jobs without a token', function () {
    PrintJob::factory()->pending()->create();

    $this->getJson(route('api.print-jobs.pending'))
        ->assertUnauthorized();
});

test('the agent cannot fetch jobs with an invalid token', function () {
    PrintJob::factory()->pending()->create();

    $this->withHeaders([
        'Authorization' => 'Bearer wrong-token',
    ])->getJson(route('api.print-jobs.pending'))
        ->assertUnauthorized();
});

test('the agent can mark a job as printed', function () {
    $job = PrintJob::factory()->pending()->create();

    $response = $this->postJson(route('api.print-jobs.printed', $job), [], agentHeaders());

    $response->assertOk();
    expect($job->fresh())
        ->status->toBe(PrintJobStatus::Printed)
        ->printed_at->not->toBeNull();
});

test('the agent can mark a job as failed', function () {
    $job = PrintJob::factory()->pending()->create();

    $response = $this->postJson(route('api.print-jobs.failed', $job), [
        'error_message' => 'Printer offline',
    ], agentHeaders());

    $response->assertOk();
    expect($job->fresh())
        ->status->toBe(PrintJobStatus::Failed)
        ->error_message->toBe('Printer offline')
        ->failed_at->not->toBeNull();
});

test('marking a job as failed requires an error message', function () {
    $job = PrintJob::factory()->pending()->create();

    $this->postJson(route('api.print-jobs.failed', $job), [], agentHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['error_message']);
});

test('pending jobs include invoice details', function () {
    $invoice = Invoice::factory()->create();
    PrintJob::factory()->create([
        'invoice_id' => $invoice->id,
        'lab_invoice_id' => null,
        'payload' => ['type' => 'invoice', 'source' => 'web'],
    ]);

    $response = $this->getJson(route('api.print-jobs.pending'), agentHeaders());

    $response->assertOk()
        ->assertJsonPath('data.0.invoice.invoice_number', $invoice->invoice_number)
        ->assertJsonPath('data.0.invoice.patient.name', $invoice->patient->name);
});
