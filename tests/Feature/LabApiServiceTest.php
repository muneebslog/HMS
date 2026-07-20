<?php

use App\Enums\LabApiStatus;
use App\Jobs\SendLabCaseToLab;
use App\Models\AdminNotification;
use App\Models\LabApiLog;
use App\Models\LabInvoice;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Shift;
use App\Models\User;
use App\Services\LabApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('services.lab.url', 'https://lab.mohsinmedicalcomplex.com');
    Config::set('services.lab.token', 'test-token');
    Config::set('services.lab.enabled', true);
});

test('only in-house tests with numeric codes are sent to the lab api', function () {
    Http::fake([
        'https://lab.mohsinmedicalcomplex.com/api/hms/lab-cases' => Http::response(['message' => 'Created'], 201),
    ]);

    $invoice = createLabInvoice([
        ['test_code' => '1300', 'is_in_house' => true],
        ['test_code' => '2704', 'is_in_house' => true],
        ['test_code' => 'EXT-01', 'is_in_house' => false],
        ['test_code' => 'ABC', 'is_in_house' => true],
    ]);

    $service = app(LabApiService::class);
    $result = $service->sendLabCase($invoice);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) use ($invoice) {
        return $request->url() === 'https://lab.mohsinmedicalcomplex.com/api/hms/lab-cases'
            && $request->header('Authorization')[0] === 'Bearer test-token'
            && $request['invoice_number'] === $invoice->invoice_number
            && $request['test_codes'] === ['1300', '2704'];
    });

    expect(AdminNotification::where('type', 'lab_test_missing_code')->count())->toBe(1);

    $log = LabApiLog::where('lab_invoice_id', $invoice->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe(LabApiStatus::Sent)
        ->and($log->http_status)->toBe(201)
        ->and($log->request_payload['invoice_number'])->toBe($invoice->invoice_number)
        ->and($log->request_payload['test_codes'])->toBe(['1300', '2704'])
        ->and($log->lab_case_url)->toBe('https://lab.mohsinmedicalcomplex.com/my-visit/'.$invoice->invoice_number);
});

test('service returns true when lab api integration is disabled', function () {
    Config::set('services.lab.enabled', false);

    $invoice = createLabInvoice([['test_code' => '1300', 'is_in_house' => true]]);

    $service = app(LabApiService::class);

    expect($service->sendLabCase($invoice))->toBeTrue();
    Http::assertNothingSent();

    $log = LabApiLog::where('lab_invoice_id', $invoice->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe(LabApiStatus::Skipped);
});

test('service returns false on lab api failure', function () {
    Http::fake([
        'https://lab.mohsinmedicalcomplex.com/api/hms/lab-cases' => Http::response(['message' => 'Error'], 500),
    ]);

    $invoice = createLabInvoice([['test_code' => '1300', 'is_in_house' => true]]);

    $service = app(LabApiService::class);

    expect($service->sendLabCase($invoice))->toBeFalse();

    $log = LabApiLog::where('lab_invoice_id', $invoice->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe(LabApiStatus::Failed)
        ->and($log->http_status)->toBe(500);
});

test('service logs a skipped status when there are no sendable in-house tests', function () {
    $invoice = createLabInvoice([
        ['test_code' => 'EXT-01', 'is_in_house' => false],
        ['test_code' => 'ABC', 'is_in_house' => true],
    ]);

    $service = app(LabApiService::class);

    expect($service->sendLabCase($invoice))->toBeTrue();
    Http::assertNothingSent();

    $log = LabApiLog::where('lab_invoice_id', $invoice->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe(LabApiStatus::Skipped);
});

test('queued job retries on lab api failure and notifies admins after final failure', function () {
    Http::fake([
        'https://lab.mohsinmedicalcomplex.com/api/hms/lab-cases' => Http::response(['message' => 'Error'], 500),
    ]);

    $invoice = createLabInvoice([['test_code' => '1300', 'is_in_house' => true]]);

    $job = new SendLabCaseToLab($invoice->id);

    expect(fn () => $job->handle(app(LabApiService::class)))->toThrow(RuntimeException::class);

    $job->failed(new RuntimeException('Lab API unavailable'));

    expect(AdminNotification::where('type', 'lab_case_sync_failed')->count())->toBe(1);

    $log = LabApiLog::where('lab_invoice_id', $invoice->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe(LabApiStatus::Failed)
        ->and($log->http_status)->toBe(500);
});

function createLabInvoice(array $itemsConfig): LabInvoice
{
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->create();

    $invoice = LabInvoice::create([
        'patient_id' => $patient->id,
        'invoice_number' => LabInvoice::generateNumber(),
        'subtotal' => 0,
        'discount_percentage' => 0,
        'discount_amount' => 0,
        'total' => 0,
        'status' => 'paid',
        'created_by' => $user->id,
        'shift_id' => Shift::current()?->id,
    ]);

    $subtotal = 0;

    foreach ($itemsConfig as $config) {
        $labTest = LabTest::factory()->create([
            'test_name' => fake()->words(3, true),
            'test_code' => $config['test_code'],
            'test_price' => 1000.00,
            'is_in_house' => $config['is_in_house'],
        ]);

        $invoice->items()->create([
            'lab_test_id' => $labTest->id,
            'test_name' => $labTest->test_name,
            'test_code' => $labTest->test_code,
            'time_required' => '1 day',
            'is_in_house' => $labTest->is_in_house,
            'price' => $labTest->test_price,
        ]);

        $subtotal += $labTest->test_price;
    }

    $invoice->update(['subtotal' => $subtotal, 'total' => $subtotal]);

    return $invoice;
}
