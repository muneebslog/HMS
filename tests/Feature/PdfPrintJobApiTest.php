<?php

use App\Enums\PrintJobStatus;
use App\Models\PdfPrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pdf_print_agent.token', 'test-pdf-agent-token');
    Storage::fake('local');
});

function pdfAgentHeaders(): array
{
    return [
        'Authorization' => 'Bearer test-pdf-agent-token',
        'Accept' => 'application/json',
    ];
}

test('the agent can fetch pending pdf print jobs', function () {
    $pending = PdfPrintJob::factory()->pending()->create();
    PdfPrintJob::factory()->printed()->create();
    PdfPrintJob::factory()->failed()->create();

    $response = $this->getJson(route('api.pdf-print-jobs.pending'), pdfAgentHeaders());

    $response->assertOk()
        ->assertJsonPath('data.0.id', $pending->id)
        ->assertJsonPath('data.0.status', PrintJobStatus::Pending->value)
        ->assertJsonPath('data.0.original_filename', $pending->original_filename)
        ->assertJsonPath('data.0.download_url', '/api/pdf-print-jobs/'.$pending->id.'/file');
});

test('the agent cannot fetch pdf jobs without a token', function () {
    PdfPrintJob::factory()->pending()->create();

    $this->getJson(route('api.pdf-print-jobs.pending'))
        ->assertUnauthorized();
});

test('the agent cannot fetch pdf jobs with an invalid token', function () {
    PdfPrintJob::factory()->pending()->create();

    $this->withHeaders([
        'Authorization' => 'Bearer wrong-token',
    ])->getJson(route('api.pdf-print-jobs.pending'))
        ->assertUnauthorized();
});

test('the agent can download the pdf file', function () {
    $job = PdfPrintJob::factory()->pending()->create();

    $response = $this->get(route('api.pdf-print-jobs.file', $job), pdfAgentHeaders());

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('the agent gets not found when the pdf file is missing', function () {
    $job = PdfPrintJob::factory()->pending()->create([
        'disk_path' => 'pdf-print-jobs/missing.pdf',
    ]);

    $this->getJson(route('api.pdf-print-jobs.file', $job), pdfAgentHeaders())
        ->assertNotFound();
});

test('the agent can mark a pdf job as printed', function () {
    $job = PdfPrintJob::factory()->pending()->create();

    $response = $this->postJson(route('api.pdf-print-jobs.printed', $job), [], pdfAgentHeaders());

    $response->assertOk();
    expect($job->fresh())
        ->status->toBe(PrintJobStatus::Printed)
        ->printed_at->not->toBeNull();
});

test('the agent can mark a pdf job as failed', function () {
    $job = PdfPrintJob::factory()->pending()->create();

    $response = $this->postJson(route('api.pdf-print-jobs.failed', $job), [
        'error_message' => 'Printer offline',
    ], pdfAgentHeaders());

    $response->assertOk();
    expect($job->fresh())
        ->status->toBe(PrintJobStatus::Failed)
        ->error_message->toBe('Printer offline')
        ->failed_at->not->toBeNull();
});

test('marking a pdf job as failed requires an error message', function () {
    $job = PdfPrintJob::factory()->pending()->create();

    $this->postJson(route('api.pdf-print-jobs.failed', $job), [], pdfAgentHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['error_message']);
});
