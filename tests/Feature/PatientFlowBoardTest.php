<?php

use App\Enums\ClinicStation;
use App\Enums\DripChargeStatus;
use App\Enums\DripLineStatus;
use App\Enums\MedicationOrderStatus;
use App\Enums\MedicineDose;
use App\Enums\StationType;
use App\Enums\TokenResetType;
use App\Models\DripBase;
use App\Models\DripCharge;
use App\Models\HealthAide;
use App\Models\MedicationOrder;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\StationSession;
use App\Models\User;
use App\Models\Vital;
use App\Services\PatientFlowBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: Shift, 1: ServiceQueue, 2: QueueToken, 3: Patient}
 */
function createFlowToken(array $serviceAttrs = [], string $status = 'waiting'): array
{
    $shift = Shift::factory()->open()->create();
    $service = Service::factory()->create(array_merge([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ], $serviceAttrs));
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'shift_id' => $shift->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'opened_at' => now(),
    ]);
    $patient = Patient::factory()->create();
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => $status,
        'arrived_at' => now()->subMinutes(15),
    ]);

    return [$shift, $queue, $token->fresh(['serviceQueue.service', 'patient', 'vital', 'medicationOrder']), $patient];
}

test('admin can view patient flow board', function () {
    $admin = User::factory()->admin()->create();
    Shift::factory()->open()->create();

    $this->actingAs($admin)
        ->get(route('admin.patient-flow'))
        ->assertSuccessful()
        ->assertSee(__('Patient Flow'));
});

test('patient needing vitals is placed in vitals column', function () {
    [, , $token] = createFlowToken(['needs_vitals' => true, 'needs_medication' => true]);

    $board = app(PatientFlowBoardService::class)->board();

    expect(collect($board['stations']['vitals'])->pluck('token_id'))->toContain($token->id);
});

test('patient waiting for doctor medication is placed in doctor column', function () {
    [, , $token] = createFlowToken(['needs_vitals' => false, 'needs_medication' => true]);

    $board = app(PatientFlowBoardService::class)->board();

    expect(collect($board['stations']['doctor'])->pluck('token_id'))->toContain($token->id);
});

test('patient with unpaid drip charge is placed in reception column', function () {
    $user = User::factory()->create();
    [, , $token, $patient] = createFlowToken(['needs_medication' => true]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'status' => MedicationOrderStatus::Pending,
    ]);
    $dripService = Service::factory()->drip()->create();
    DripCharge::factory()->create([
        'patient_id' => $patient->id,
        'queue_token_id' => $token->id,
        'medication_order_id' => $order->id,
        'service_id' => $dripService->id,
        'status' => DripChargeStatus::Pending,
        'suggested_by' => $user->id,
    ]);

    $token = $token->fresh([
        'serviceQueue.service',
        'patient',
        'vital',
        'medicationOrder.medicines',
        'medicationOrder.injections',
        'medicationOrder.drips',
    ]);

    $resolved = app(PatientFlowBoardService::class)->resolveStation($token, collect([$token->id]));

    expect($resolved['station'])->toBe(ClinicStation::Reception);
});

test('patient with pending drip line is placed in drip column', function () {
    $user = User::factory()->create();
    [, , $token, $patient] = createFlowToken(['needs_medication' => true]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'status' => MedicationOrderStatus::Pending,
    ]);
    $dripBase = DripBase::factory()->create();
    $order->drips()->create([
        'drip_base_id' => $dripBase->id,
        'name' => $dripBase->name,
        'status' => DripLineStatus::Pending,
    ]);

    $token = $token->fresh([
        'serviceQueue.service',
        'patient',
        'vital',
        'medicationOrder.medicines',
        'medicationOrder.injections',
        'medicationOrder.drips',
    ]);

    $resolved = app(PatientFlowBoardService::class)->resolveStation($token, collect());

    expect($resolved['station'])->toBe(ClinicStation::Drip);
});

test('patient with undelivered medicines is placed in er column', function () {
    $user = User::factory()->create();
    [, , $token, $patient] = createFlowToken(['needs_medication' => true]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'status' => MedicationOrderStatus::Pending,
    ]);
    $medicine = Medicine::factory()->create();
    $order->medicines()->create([
        'medicine_id' => $medicine->id,
        'dose' => MedicineDose::OneZeroOne,
        'name' => $medicine->name,
    ]);

    $token = $token->fresh([
        'serviceQueue.service',
        'patient',
        'vital',
        'medicationOrder.medicines',
        'medicationOrder.injections',
        'medicationOrder.drips',
    ]);

    $resolved = app(PatientFlowBoardService::class)->resolveStation($token, collect());

    expect($resolved['station'])->toBe(ClinicStation::Er);
});

test('services without medication needs are excluded from the board', function () {
    [, , $token] = createFlowToken(['appear_on_er' => true, 'needs_medication' => false]);

    $board = app(PatientFlowBoardService::class)->board();
    $tokenIds = collect($board['stations'])->flatten(1)->pluck('token_id');

    expect($tokenIds)->not->toContain($token->id);
});

test('board shows expired aide login status', function () {
    Shift::factory()->open()->create();
    $aide = HealthAide::factory()->create(['name' => 'Expired Aide']);
    StationSession::factory()->expired()->create([
        'station' => StationType::Er,
        'health_aide_id' => $aide->id,
    ]);

    $board = app(PatientFlowBoardService::class)->board();

    expect($board['aide_sessions']['er']['status'])->toBe('expired')
        ->and($board['aide_sessions']['er']['aide_name'])->toBe('Expired Aide');
});

test('vitals recorded patient moves past vitals stage', function () {
    $user = User::factory()->create();
    [, , $token, $patient] = createFlowToken(['needs_vitals' => true, 'needs_medication' => true]);
    Vital::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'recorded_by' => $user->id,
    ]);

    $token = $token->fresh([
        'serviceQueue.service',
        'patient',
        'vital',
        'medicationOrder.medicines',
        'medicationOrder.injections',
        'medicationOrder.drips',
    ]);

    $resolved = app(PatientFlowBoardService::class)->resolveStation($token, collect());

    expect($resolved['station'])->toBe(ClinicStation::Doctor);
});

test('recalled draft medication order returns the patient to the doctor column', function () {
    [, , $token, $patient] = createFlowToken(['needs_medication' => true]);

    MedicationOrder::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'status' => MedicationOrderStatus::Draft,
    ]);

    $token = $token->fresh([
        'serviceQueue.service',
        'patient',
        'vital',
        'medicationOrder.medicines',
        'medicationOrder.injections',
        'medicationOrder.drips',
    ]);

    $resolved = app(PatientFlowBoardService::class)->resolveStation($token, collect());

    expect($resolved['station'])->toBe(ClinicStation::Doctor);
});

test('service ending at vitals is done after vitals are recorded', function () {
    $user = User::factory()->create();
    [, , $token, $patient] = createFlowToken([
        'ends_at_vitals' => true,
        'needs_medication' => true,
        'appear_on_er' => true,
    ]);

    $beforeVitals = app(PatientFlowBoardService::class)->resolveStation($token, collect());

    Vital::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'recorded_by' => $user->id,
    ]);

    $token = $token->fresh([
        'serviceQueue.service',
        'patient',
        'vital',
        'medicationOrder.medicines',
        'medicationOrder.injections',
        'medicationOrder.drips',
    ]);

    $afterVitals = app(PatientFlowBoardService::class)->resolveStation($token, collect());

    expect($beforeVitals['station'])->toBe(ClinicStation::Vitals)
        ->and($afterVitals['station'])->toBe(ClinicStation::Done);
});

test('undelivered medication stays on the er column after the shift is closed', function () {
    $user = User::factory()->create();
    [$shift, , $token, $patient] = createFlowToken(['needs_medication' => true]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'status' => MedicationOrderStatus::Pending,
    ]);
    $medicine = Medicine::factory()->create();
    $order->medicines()->create([
        'medicine_id' => $medicine->id,
        'dose' => MedicineDose::OneZeroOne,
        'name' => $medicine->name,
    ]);

    $shift->update([
        'status' => 'closed',
        'closed_at' => now(),
        'closing_balance' => 0,
    ]);

    $board = app(PatientFlowBoardService::class)->board();

    expect(collect($board['stations']['er'])->pluck('token_id'))->toContain($token->id);
});

test('pending drip stays on the drip column after the shift is closed', function () {
    $user = User::factory()->create();
    [$shift, , $token, $patient] = createFlowToken(['needs_medication' => true]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'status' => MedicationOrderStatus::Pending,
    ]);
    $dripBase = DripBase::factory()->create();
    $order->drips()->create([
        'drip_base_id' => $dripBase->id,
        'name' => $dripBase->name,
        'status' => DripLineStatus::Pending,
    ]);

    $shift->update([
        'status' => 'closed',
        'closed_at' => now(),
        'closing_balance' => 0,
    ]);

    $board = app(PatientFlowBoardService::class)->board();

    expect(collect($board['stations']['drip'])->pluck('token_id'))->toContain($token->id);
});

test('appear_on_er visits without medication needs stay off the board when the shift closes', function () {
    [$shift, , $token] = createFlowToken(['appear_on_er' => true, 'needs_medication' => false]);

    $shift->update([
        'status' => 'closed',
        'closed_at' => now(),
        'closing_balance' => 0,
    ]);

    $board = app(PatientFlowBoardService::class)->board();

    expect(collect($board['stations']['er'])->pluck('token_id'))->not->toContain($token->id);
});

test('completed medication visit stays in the done column', function () {
    $user = User::factory()->create();
    [, , $token, $patient] = createFlowToken(['needs_medication' => true]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'status' => MedicationOrderStatus::Administered,
        'administered_at' => now(),
    ]);
    $medicine = Medicine::factory()->create();
    $order->medicines()->create([
        'medicine_id' => $medicine->id,
        'dose' => MedicineDose::OneZeroOne,
        'name' => $medicine->name,
        'delivered_at' => now(),
    ]);

    $board = app(PatientFlowBoardService::class)->board();

    expect(collect($board['stations']['done'])->pluck('token_id'))->toContain($token->id)
        ->and(collect($board['stations']['er'])->pluck('token_id'))->not->toContain($token->id);
});

test('patient flow livewire page renders station columns', function () {
    $admin = User::factory()->admin()->create();
    [, , $token] = createFlowToken(['needs_medication' => true]);

    Livewire::actingAs($admin)
        ->test('pages::admin.patient-flow')
        ->assertSee(__('ER Station'))
        ->assertSee(__('Vitals'))
        ->assertSee(__('Done'))
        ->assertSee($token->patient->name)
        ->assertSeeHtml('wire:poll.5s');
});
