<?php

use App\Jobs\SendLabCaseToLab;
use App\Models\LabApiLog;
use App\Models\LabInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('retry failed lab cases command dispatches jobs for failed api logs', function () {
    Bus::fake();

    $failedInvoice = LabInvoice::factory()->paid()->create();
    LabApiLog::factory()->failed()->create(['lab_invoice_id' => $failedInvoice->id]);

    $sentInvoice = LabInvoice::factory()->paid()->create();
    LabApiLog::factory()->sent()->create(['lab_invoice_id' => $sentInvoice->id]);

    Artisan::call('lab:retry-failed-cases');

    Bus::assertDispatched(SendLabCaseToLab::class, fn (SendLabCaseToLab $job) => $job->labInvoiceId === $failedInvoice->id);
    Bus::assertNotDispatched(SendLabCaseToLab::class, fn (SendLabCaseToLab $job) => $job->labInvoiceId === $sentInvoice->id);
});

test('retry failed lab cases command reports when there is nothing to retry', function () {
    Bus::fake();

    Artisan::call('lab:retry-failed-cases');

    expect(Artisan::output())->toContain('No failed lab-case syncs to retry.');
    Bus::assertNothingDispatched();
});

test('retry failed lab cases command clears matching failed jobs', function () {
    Bus::fake();

    $invoice = LabInvoice::factory()->paid()->create();
    LabApiLog::factory()->failed()->create(['lab_invoice_id' => $invoice->id]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => SendLabCaseToLab::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => SendLabCaseToLab::class],
        ]),
        'exception' => 'Timed out',
        'failed_at' => now(),
    ]);

    Artisan::call('lab:retry-failed-cases');

    expect(DB::table('failed_jobs')->count())->toBe(0);
    Bus::assertDispatched(SendLabCaseToLab::class, fn (SendLabCaseToLab $job) => $job->labInvoiceId === $invoice->id);
});
