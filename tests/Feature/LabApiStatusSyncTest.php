<?php

use App\Enums\LabResultsStatus;
use App\Models\LabApiLog;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Services\LabApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('services.lab.url', 'https://lab.example.test');
    Config::set('services.lab.token', 'test-token');
    Config::set('services.lab.enabled', true);
});

test('syncing invoice statuses maps lab api readiness onto in-house items', function () {
    $invoice = LabInvoice::factory()->paid()->create([
        'invoice_number' => '928100',
    ]);

    $readyItem = LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $invoice->id,
        'test_code' => '1300',
        'test_name' => 'CBC',
    ]);
    $pendingItem = LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $invoice->id,
        'test_code' => '1400',
        'test_name' => 'LFT',
    ]);
    LabInvoiceItem::factory()->outgoing()->create([
        'lab_invoice_id' => $invoice->id,
        'test_code' => null,
        'test_name' => 'Send out culture',
    ]);

    LabApiLog::factory()->sent()->create([
        'lab_invoice_id' => $invoice->id,
        'lab_case_url' => 'https://lab.example.test/my-visit/928100',
    ]);

    Http::fake([
        'https://lab.example.test/api/hms/lab-cases/928100' => Http::response([
            'receipt_no' => '928100',
            'patient_id' => 9,
            'invoice_url' => 'https://lab.example.test/my-visit/928100',
            'tests' => [
                [
                    'test_code' => '1300',
                    'test_name' => 'CBC',
                    'is_result_added' => true,
                    'is_printed' => false,
                    'printed_at' => null,
                    'patient_test_id' => 1,
                ],
                [
                    'test_code' => '1400',
                    'test_name' => 'LFT',
                    'is_result_added' => false,
                    'is_printed' => false,
                    'printed_at' => null,
                    'patient_test_id' => 2,
                ],
            ],
            'summary' => [
                'total' => 2,
                'completed' => 1,
                'pending' => 1,
                'all_ready' => false,
            ],
        ]),
    ]);

    $synced = app(LabApiService::class)->syncInvoiceStatuses($invoice->fresh(['items', 'labApiLog']));

    expect($synced)->toBeTrue();

    expect($readyItem->fresh()->lab_result_ready)->toBeTrue();
    expect($pendingItem->fresh()->lab_result_ready)->toBeFalse();
    expect($invoice->fresh()->lab_results_status)->toBe(LabResultsStatus::Partial)
        ->and($invoice->fresh()->lab_results_synced_at)->not->toBeNull();
});

test('syncPendingInvoices only processes sent invoices that are not ready', function () {
    $pending = LabInvoice::factory()->paid()->create(['invoice_number' => '928200']);
    LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $pending->id,
        'test_code' => '1300',
    ]);
    LabApiLog::factory()->sent()->create(['lab_invoice_id' => $pending->id]);

    $ready = LabInvoice::factory()->paid()->create([
        'invoice_number' => '928201',
        'lab_results_status' => LabResultsStatus::Ready,
        'lab_results_synced_at' => now(),
    ]);
    LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $ready->id,
        'test_code' => '1300',
        'lab_result_ready' => true,
    ]);
    LabApiLog::factory()->sent()->create(['lab_invoice_id' => $ready->id]);

    Http::fake([
        'https://lab.example.test/api/hms/lab-cases/928200' => Http::response([
            'receipt_no' => '928200',
            'patient_id' => 1,
            'invoice_url' => 'https://lab.example.test/my-visit/928200',
            'tests' => [
                [
                    'test_code' => '1300',
                    'test_name' => 'CBC',
                    'is_result_added' => true,
                    'is_printed' => true,
                    'printed_at' => now()->toISOString(),
                    'patient_test_id' => 11,
                ],
            ],
            'summary' => [
                'total' => 1,
                'completed' => 1,
                'pending' => 0,
                'all_ready' => true,
            ],
        ]),
    ]);

    $count = app(LabApiService::class)->syncPendingInvoices();

    expect($count)->toBe(1);
    expect($pending->fresh()->lab_results_status)->toBe(LabResultsStatus::Ready);
    expect($ready->fresh()->lab_results_status)->toBe(LabResultsStatus::Ready);

    Http::assertSentCount(1);
});

test('fetchLabCaseStatus returns null when the lab case is missing', function () {
    Http::fake([
        'https://lab.example.test/api/hms/lab-cases/missing' => Http::response(['message' => 'Lab case not found.'], 404),
    ]);

    expect(app(LabApiService::class)->fetchLabCaseStatus('missing'))->toBeNull();
});
