<?php

use App\Enums\OutgoingSampleStatus;
use App\Mcp\Servers\HermesServer;
use App\Mcp\Tools\DailyDigestTool;
use App\Mcp\Tools\LabStatusTool;
use App\Mcp\Tools\NeedsAttentionTool;
use App\Mcp\Tools\PrescriptionsTool;
use App\Mcp\Tools\ProceduresTool;
use App\Mcp\Tools\ShiftSummaryTool;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use App\Models\MedicationOrder;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\ShiftSettlement;
use App\Services\Hermes\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 15:00'));
    config([
        'services.hermes_mcp.token' => 'secret-token',
        'services.hermes_mcp.allowed_ips' => '172.16.0.0/12',
    ]);
});

/**
 * @return array{night: Shift, morning: Shift, evening: Shift}
 */
function hermesBusinessDayShifts(): array
{
    return [
        'night' => Shift::factory()->create(['opened_at' => '2026-10-14 19:00', 'closed_at' => '2026-10-15 08:00', 'opening_balance' => 0]),
        'morning' => Shift::factory()->create(['opened_at' => '2026-10-15 08:00', 'closed_at' => '2026-10-15 14:00', 'opening_balance' => 0]),
        'evening' => Shift::factory()->open()->create(['opened_at' => '2026-10-15 14:00', 'opening_balance' => 0]),
    ];
}

/**
 * Make an arrived General Checkup token billed in the given shift.
 *
 * @param  array<string, mixed>  $attributes
 */
function hermesCheckupToken(Shift $shift, string $at, array $attributes = [], string $invoiceState = 'paid'): QueueToken
{
    $service = Service::query()->firstOrCreate(['name' => 'General Checkup'], Service::factory()->raw(['name' => 'General Checkup', 'needs_medication' => true]));
    $queue = ServiceQueue::query()->firstOrCreate(['service_id' => $service->id, 'shift_id' => $shift->id], ServiceQueue::factory()->raw(['service_id' => $service->id, 'shift_id' => $shift->id, 'doctor_id' => null]));
    $patient = Patient::factory()->create(['name' => 'Secret Patient Name', 'mrn' => 'MR-'.fake()->unique()->numberBetween(1000, 9999)]);
    $invoice = Invoice::factory()->{$invoiceState}()->create(['patient_id' => $patient->id, 'shift_id' => $shift->id, 'created_at' => $at]);
    $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'service_id' => $service->id, 'service_name' => $service->name]);

    return QueueToken::factory()->create(array_merge([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => $item->id,
        'patient_id' => $patient->id,
        'token_number' => $queue->tokens()->count() + 1,
        'created_at' => $at,
    ], $attributes));
}

function hermesPrescribe(QueueToken $token, string $at): MedicationOrder
{
    return MedicationOrder::factory()->create(['queue_token_id' => $token->id, 'patient_id' => $token->patient_id, 'created_at' => $at]);
}

/**
 * @return array<string, mixed>
 */
function hermesInitializePayload(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ];
}

test('the endpoint refuses requests that do not come from the docker network', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withToken('secret-token')
        ->postJson('/mcp/hermes', hermesInitializePayload())
        ->assertForbidden();
});

test('forwarded headers cannot spoof the docker network', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => '172.17.0.2', 'CF-Connecting-IP' => '172.17.0.2'])
        ->withToken('secret-token')
        ->postJson('/mcp/hermes', hermesInitializePayload())
        ->assertForbidden();
});

test('the endpoint requires the hermes token', function (?string $token) {
    $request = $this->withServerVariables(['REMOTE_ADDR' => '172.17.0.2']);

    if ($token !== null) {
        $request = $request->withToken($token);
    }

    $request->postJson('/mcp/hermes', hermesInitializePayload())->assertUnauthorized();
})->with([
    'missing' => [null],
    'wrong' => ['not-the-token'],
]);

test('each assistant can use its own token', function (string $token) {
    config(['services.hermes_mcp.token' => 'muneeb-token, brother-token']);

    $this->withServerVariables(['REMOTE_ADDR' => '172.17.0.3'])
        ->withToken($token)
        ->postJson('/mcp/hermes', hermesInitializePayload(), ['Accept' => 'application/json, text/event-stream'])
        ->assertOk();
})->with(['muneeb-token', 'brother-token']);

test('the endpoint is closed when no token is configured', function () {
    config(['services.hermes_mcp.token' => null]);

    $this->withServerVariables(['REMOTE_ADDR' => '172.17.0.2'])
        ->withToken('')
        ->postJson('/mcp/hermes', hermesInitializePayload())
        ->assertUnauthorized();
});

test('hermes can initialize and list the read-only tools', function () {
    $headers = ['Accept' => 'application/json, text/event-stream'];

    $this->withServerVariables(['REMOTE_ADDR' => '172.17.0.2'])
        ->withToken('secret-token')
        ->postJson('/mcp/hermes', hermesInitializePayload(), $headers)
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Mohsin Medical Complex HMS');

    $tools = $this->withServerVariables(['REMOTE_ADDR' => '172.17.0.2'])
        ->withToken('secret-token')
        ->postJson('/mcp/hermes', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers)
        ->assertOk()
        ->json('result.tools');

    expect(collect($tools)->pluck('name')->sort()->values()->all())
        ->toBe(['daily_digest', 'general_checkup_prescriptions', 'lab_status', 'needs_attention', 'procedures', 'shift_summary'])
        ->and(collect($tools)->every(fn (array $tool) => $tool['annotations']['readOnlyHint'] ?? false))->toBeTrue();
});

test('a business day starts with the night shift opened the evening before', function () {
    $shifts = hermesBusinessDayShifts();
    Shift::factory()->create(['opened_at' => '2026-10-14 08:00', 'closed_at' => '2026-10-14 14:00']);

    $period = ReportPeriod::resolve('today');

    expect($period->shiftIds())->toBe([$shifts['night']->id, $shifts['morning']->id, $shifts['evening']->id])
        ->and($period->from->toDateTimeString())->toBe('2026-10-14 19:00:00')
        ->and($period->to->toDateTimeString())->toBe('2026-10-15 15:00:00')
        ->and(ReportPeriod::resolve(null, 'current')->shiftIds())->toBe([$shifts['evening']->id]);
});

test('a business day without shifts covers 18:00 to 18:00', function () {
    $period = ReportPeriod::resolve('2026-10-10');

    expect($period->shiftIds())->toBe([])
        ->and($period->from->toDateTimeString())->toBe('2026-10-09 18:00:00')
        ->and($period->to->toDateTimeString())->toBe('2026-10-10 18:00:00');
});

test('report tools reject bad dates and unknown shifts', function () {
    HermesServer::tool(ShiftSummaryTool::class, ['date' => 'last tuesday'])->assertHasErrors();
    HermesServer::tool(ShiftSummaryTool::class, ['shift' => '999999'])->assertHasErrors(['Shift 999999 was not found.']);
    HermesServer::tool(ShiftSummaryTool::class, ['shift' => 'drop table'])->assertHasErrors();
});

test('lab status shows open stages, samples to send out and retakes without patient names', function () {
    $patient = Patient::factory()->create(['name' => 'Secret Patient Name', 'mrn' => 'MR-5001']);
    $case = LabInvoice::factory()->paid()->create(['patient_id' => $patient->id, 'invoice_number' => 'LAB-1']);
    $item = fn (array $attributes) => LabInvoiceItem::factory()->create(array_merge([
        'lab_invoice_id' => $case->id, 'is_in_house' => true, 'outgoing_status' => null, 'time_required' => 'Same day',
    ], $attributes));

    $item(['test_name' => 'Vitamin D', 'is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Pending]);
    $item(['test_name' => 'CBC', 'sample_received_at' => now()]);
    $item(['test_name' => 'LFT', 'sample_received_at' => now(), 'results_completed_at' => now()]);
    LabSampleRetake::factory()->create(['lab_invoice_item_id' => $item(['test_name' => 'Clotted'])->id, 'reason' => 'Clotted sample']);

    HermesServer::tool(LabStatusTool::class)
        ->assertOk()
        ->assertSee(['Vitamin D', 'MR-5001', 'Clotted sample', 'LAB-1'])
        ->assertDontSee('Secret Patient Name')
        ->assertStructuredContent(function ($json) {
            $json->where('today.done_today', 1)
                ->where('today.retakes', 1)
                ->where('stages', fn ($stages) => collect($stages)->pluck('count', 'key')->all() === [
                    'rider_not_called' => 1, 'waiting_rider' => 0, 'partner_lab' => 0, 'not_received' => 0, 'retake' => 1, 'results_pending' => 1,
                ])
                ->where('samples_to_send_out.0.test', 'Vitamin D')
                ->where('retakes.0.patient_contacted', false)
                ->etc();
        });
});

test('prescription coverage counts checkup patients with and without a prescription', function () {
    $shifts = hermesBusinessDayShifts();

    hermesPrescribe(hermesCheckupToken($shifts['night'], '2026-10-14 20:00'), '2026-10-14 20:10');
    hermesCheckupToken($shifts['morning'], '2026-10-15 09:00');
    hermesPrescribe(hermesCheckupToken($shifts['morning'], '2026-10-15 13:30'), '2026-10-15 14:20');
    hermesCheckupToken($shifts['morning'], '2026-10-15 10:00', [], 'returned');
    hermesCheckupToken($shifts['morning'], '2026-10-15 10:30', ['status' => 'reserved', 'arrived_at' => null]);

    $yesterday = Shift::factory()->create(['opened_at' => '2026-10-14 08:00', 'closed_at' => '2026-10-14 14:00']);
    hermesPrescribe(hermesCheckupToken($yesterday, '2026-10-14 13:50'), '2026-10-14 19:30');

    HermesServer::tool(PrescriptionsTool::class, ['date' => '2026-10-15'])
        ->assertOk()
        ->assertDontSee('Secret Patient Name')
        ->assertStructuredContent(function ($json) {
            $json->where('totals.patients', 3)
                ->where('totals.with_prescription', 2)
                ->where('totals.without_prescription', 1)
                ->where('by_service.0.service', 'General Checkup')
                ->where('entered_after_shift_closed.count', 1)
                ->where('entered_after_shift_closed.patients.0.delay_minutes', 50)
                ->where('entered_this_period_for_earlier_patients.count', 1)
                ->where('without_prescription_latest.0.token_at', '2026-10-15 09:00:00')
                ->etc();
        });
});

test('a single shift can be reported on its own', function () {
    $shifts = hermesBusinessDayShifts();
    hermesCheckupToken($shifts['night'], '2026-10-14 20:00');
    hermesCheckupToken($shifts['morning'], '2026-10-15 09:00');

    HermesServer::tool(PrescriptionsTool::class, ['shift' => (string) $shifts['morning']->id])
        ->assertStructuredContent(fn ($json) => $json->where('totals.patients', 1)->where('period.shift_ids', [$shifts['morning']->id])->etc());
});

test('procedures lists what was added in the period and who is still on the ward', function () {
    hermesBusinessDayShifts();
    $patient = Patient::factory()->create(['name' => 'Secret Patient Name', 'mrn' => 'MR-7001']);

    Procedure::factory()->admitted()->create(['patient_id' => $patient->id, 'full_amount' => 50000, 'created_at' => '2026-10-15 10:00']);
    Procedure::factory()->create(['created_at' => '2026-10-15 11:00']);
    Procedure::factory()->create(['created_at' => '2026-10-13 11:00']);

    HermesServer::tool(ProceduresTool::class)
        ->assertOk()
        ->assertSee('MR-7001')
        ->assertDontSee('Secret Patient Name')
        ->assertStructuredContent(function ($json) {
            $json->where('counts.added', 2)
                ->where('counts.on_ward_now', 1)
                ->where('on_ward.0.patient_mrn', 'MR-7001')
                ->where('on_ward.0.balance', 50000.0)
                ->etc();
        });
});

test('shift summary totals sales and slips per shift of the business day', function () {
    $shifts = hermesBusinessDayShifts();
    Invoice::factory()->paid()->create(['shift_id' => $shifts['night']->id, 'total' => 500]);
    Invoice::factory()->paid()->create(['shift_id' => $shifts['morning']->id, 'total' => 1200]);
    Invoice::factory()->returned()->create(['shift_id' => $shifts['morning']->id, 'total' => 300]);

    HermesServer::tool(ShiftSummaryTool::class)
        ->assertOk()
        ->assertStructuredContent(function ($json) use ($shifts) {
            $json->where('shift_count', 3)
                ->where('totals.money.walk_in_sales', 1700.0)
                ->where('totals.counts.opd_slips', 2)
                ->where('totals.counts.returns', 1)
                ->where('shifts.0.id', $shifts['night']->id)
                ->where('shifts.0.period', 'Night')
                ->where('shifts.2.status', 'open')
                ->etc();
        });
});

test('the daily digest combines every report', function () {
    $shifts = hermesBusinessDayShifts();
    hermesPrescribe(hermesCheckupToken($shifts['morning'], '2026-10-15 09:00'), '2026-10-15 09:05');

    HermesServer::tool(DailyDigestTool::class)
        ->assertOk()
        ->assertDontSee('Secret Patient Name')
        ->assertStructuredContent(function ($json) {
            $json->where('general_checkup.patients', 1)
                ->where('general_checkup.with_prescription', 1)
                ->has('lab_now.open_by_stage')
                ->has('procedures.added')
                ->has('shifts', 3)
                ->etc();
        });
});

test('needs attention lists what to follow up on and who to ask', function () {
    $shifts = hermesBusinessDayShifts();
    $shifts['morning']->user->update(['name' => 'Zobia']);
    ShiftSettlement::factory()->create(['shift_id' => $shifts['morning']->id, 'difference' => -2500]);

    hermesPrescribe(hermesCheckupToken($shifts['morning'], '2026-10-15 09:00'), '2026-10-15 09:05');
    hermesCheckupToken($shifts['morning'], '2026-10-15 09:30');

    $patient = Patient::factory()->create(['name' => 'Secret Patient Name', 'mrn' => 'MR-9001']);
    $case = LabInvoice::factory()->paid()->create(['patient_id' => $patient->id]);
    LabInvoiceItem::factory()->create(['lab_invoice_id' => $case->id, 'test_name' => 'CBC', 'is_in_house' => true, 'outgoing_status' => null, 'created_at' => now()->subHours(3)]);
    LabInvoiceItem::factory()->create(['lab_invoice_id' => $case->id, 'test_name' => 'Fresh CBC', 'is_in_house' => true, 'outgoing_status' => null, 'created_at' => now()->subMinutes(10)]);

    Procedure::factory()->admitted()->create(['patient_id' => $patient->id, 'admitted_at' => now()->subDays(10), 'file_printed_at' => now()->subDays(10)]);
    Procedure::factory()->discharged()->create(['full_amount' => 40000, 'discharged_at' => now()->subDays(2)]);

    $response = HermesServer::tool(NeedsAttentionTool::class, ['date' => '2026-10-15'])
        ->assertOk()
        ->assertSee(['MR-9001', 'Zobia', 'Rs 2,500 short', 'Rs 40,000 due', '1 of 2 without prescription'])
        ->assertDontSee(['Secret Patient Name', 'Fresh CBC']);

    $response->assertStructuredContent(function ($json) {
        $json->where('issues', function ($issues) {
            $byIssue = collect($issues)->keyBy('issue');

            return $byIssue['Samples billed but not taken / not in lab (over 60 min)']['count'] === 1
                && $byIssue['Samples billed but not taken / not in lab (over 60 min)']['ask'] === 'Lab / health aide on duty'
                && $byIssue['General checkup patients without a prescription in the HMS']['count'] === 1
                && $byIssue['On the ward for more than 4 days (discharge them in the HMS if they have left)']['count'] === 1
                && $byIssue['Discharged with payment still due (last 30 days)']['count'] === 1
                && $byIssue['Shift cash short at settlement']['ask'] === 'Receptionist of that shift';
        })->where('message', fn (string $message) => str_contains($message, '*HMS daily check* — Business day 2026-10-15'))
            ->etc();
    });
});

test('needs attention says so when nothing is wrong', function () {
    HermesServer::tool(NeedsAttentionTool::class, ['date' => '2026-10-10'])
        ->assertOk()
        ->assertSee('Nothing needs your attention.');
});
