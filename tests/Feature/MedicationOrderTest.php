<?php

use App\Enums\MedicationOrderStatus;
use App\Enums\MedicineDose;
use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\DoctorRecheck;
use App\Models\DripBase;
use App\Models\Injection;
use App\Models\MedicationOrder;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: ?Doctor, 2: Shift, 3: Service, 4: ServiceQueue, 5: Patient, 6: QueueToken}
 */
function createMedicationQueuePatient(
    bool $needsMedication = true,
    string $tokenStatus = 'waiting',
    ?Doctor $doctor = null,
    bool $withDoctor = true,
    bool $followsDoctorToken = false,
): array {
    $user = User::factory()->doctor()->create();
    $doctor = $withDoctor ? ($doctor ?? Doctor::factory()->create()) : null;
    $shift = Shift::factory()->open()->create();
    $service = Service::factory()->create([
        'name' => 'General Checkup',
        'is_standalone' => ! $withDoctor,
        'needs_medication' => $needsMedication,
        'follows_doctor_token' => $followsDoctorToken,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor?->id,
        'shift_id' => $shift->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'opened_at' => now(),
    ]);
    $patient = Patient::factory()->create(['name' => 'Sana Malik']);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => $tokenStatus,
        'arrived_at' => now()->subMinutes(5),
    ]);

    return [$user, $doctor, $shift, $service, $queue, $patient, $token];
}

test('doctors can visit the medication page', function () {
    $user = User::factory()->doctor()->create();

    $this->actingAs($user)
        ->get(route('doctor.medication'))
        ->assertSuccessful();
});

test('receptionists cannot visit the doctor medication page', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('doctor.medication'))
        ->assertForbidden();
});

test('medication page lists waiting tokens without selecting a doctor profile', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->assertSee($patient->name)
        ->assertSee($patient->mrn)
        ->assertSee((string) $token->token_number)
        ->assertDontSee(__('Change doctor'));
});

test('medication queue excludes tokens for services that do not need medication', function () {
    [$user, , , , , $patient] = createMedicationQueuePatient(needsMedication: false, withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->assertDontSee($patient->name)
        ->assertSee(__('No patients need medication'));
});

test('medication queue includes overnight daily queues after midnight', function () {
    $user = User::factory()->doctor()->create();
    $shift = Shift::factory()->open()->create([
        'opened_at' => now()->subDay()->setTime(18, 0),
    ]);
    $earlierShift = Shift::factory()->closed()->create([
        'opened_at' => now()->subDay()->setTime(8, 0),
        'closed_at' => now()->subDay()->setTime(17, 0),
    ]);
    $service = Service::factory()->create([
        'name' => 'Overnight Checkup',
        'is_standalone' => true,
        'needs_medication' => true,
        'token_reset_type' => TokenResetType::Daily,
    ]);
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'shift_id' => $earlierShift->id,
        'date' => $shift->opened_at->toDateString(),
        'reset_type' => TokenResetType::Daily,
        'status' => 'open',
        'opened_at' => $earlierShift->opened_at,
    ]);
    $patient = Patient::factory()->create();
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 4,
        'status' => 'waiting',
        'arrived_at' => now()->subHour(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->assertSee($patient->name)
        ->assertSee((string) $token->token_number)
        ->assertDontSee(__('No patients need medication'));
});

test('any doctor login can prescribe for patients in the medication queue', function () {
    [, , , , , $patient] = createMedicationQueuePatient(withDoctor: false);
    $unlinkedUser = User::factory()->doctor()->create();

    Livewire::actingAs($unlinkedUser)
        ->test('pages::doctor.medication')
        ->assertSee($patient->name);
});

test('doctor sees vitals for the selected token', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);

    Vital::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'recorded_by' => User::factory()->receptionist()->create()->id,
        'temperature' => 98.6,
        'bp_systolic' => 120,
        'bp_diastolic' => 80,
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSee('98.6')
        ->assertSee('120')
        ->assertSee('80');
});

test('doctor sees vitals history table with multiple readings', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $recorder = User::factory()->receptionist()->create(['name' => 'Vitals Nurse']);

    Vital::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'recorded_by' => $recorder->id,
        'temperature' => 98.6,
        'bp_systolic' => 180,
        'bp_diastolic' => 110,
        'bsr' => 140,
    ]);

    Vital::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'recorded_by' => $recorder->id,
        'temperature' => 98.2,
        'bp_systolic' => 130,
        'bp_diastolic' => 85,
        'bsr' => 110,
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSee('180/110')
        ->assertSee('130/85')
        ->assertSee('140')
        ->assertSee('110')
        ->assertSee('Vitals Nurse');
});

test('medication queue excludes patients after an order is saved', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->assertSee($patient->name)
        ->call('selectToken', $token->id)
        ->set('medicationLines', [[
            'selection' => 'medicine:'.$medicine->id,
            'dose' => '1-0-0',
            'administration_type' => 'im',
            'comment' => '',
        ]])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDontSee($patient->name);
});

test('doctor can recall a pending medication order and edit the same order', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create(['name' => 'Existing Medicine']);
    $replacementMedicine = Medicine::factory()->create(['name' => 'Replacement Medicine']);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'status' => MedicationOrderStatus::Pending,
    ]);

    $order->medicines()->create([
        'medicine_id' => $medicine->id,
        'dose' => MedicineDose::OneZeroZero,
        'name' => $medicine->name,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->assertDontSee($patient->name)
        ->assertSeeHtml('aria-label="'.__('Recall medication order').'"')
        ->call('openRecall')
        ->assertSet('showRecallModal', true)
        ->assertSee($patient->name)
        ->assertSee(__('Tap to recall'))
        ->call('recall', $order->id)
        ->assertHasNoErrors()
        ->assertSet('showRecallModal', false)
        ->assertSee($patient->name)
        ->call('selectToken', $token->id)
        ->assertSet('medicationLines.0.selection', 'medicine:'.$medicine->id)
        ->set('medicationLines.0.selection', 'medicine:'.$replacementMedicine->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe(MedicationOrderStatus::Pending)
        ->and($token->medicationOrders()->count())->toBe(1);

    $this->assertDatabaseHas('medication_order_medicines', [
        'medication_order_id' => $order->id,
        'medicine_id' => $replacementMedicine->id,
        'name' => 'Replacement Medicine',
    ]);
});

test('recalling an administered order creates a blank draft on the same token', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(
        withDoctor: false,
        tokenStatus: 'served',
    );
    $deliveredMedicine = Medicine::factory()->create(['name' => 'Delivered Medicine']);
    $newMedicine = Medicine::factory()->create(['name' => 'New Medicine']);
    $administeredOrder = MedicationOrder::factory()->withoutDoctor()->administered($user)->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
    ]);

    $administeredOrder->medicines()->create([
        'medicine_id' => $deliveredMedicine->id,
        'dose' => MedicineDose::OneZeroOne,
        'name' => $deliveredMedicine->name,
        'delivered_at' => now(),
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('openRecall')
        ->assertSee($patient->name)
        ->assertSee(__('Administered'))
        ->call('recall', $administeredOrder->id)
        ->assertHasNoErrors()
        ->assertSee($patient->name);

    $draft = $token->medicationOrders()->firstOrFail();

    expect($draft->id)->not->toBe($administeredOrder->id)
        ->and($draft->status)->toBe(MedicationOrderStatus::Draft)
        ->and($draft->queue_token_id)->toBe($token->id)
        ->and($draft->medicines)->toHaveCount(0)
        ->and($administeredOrder->fresh()->status)->toBe(MedicationOrderStatus::Administered)
        ->and($administeredOrder->medicines)->toHaveCount(1)
        ->and($token->medicationOrders()->count())->toBe(2);

    $component
        ->call('selectToken', $token->id)
        ->assertCount('medicationLines', 6)
        ->assertSet('medicationLines.0.selection', null)
        ->set('medicationLines.0.selection', 'medicine:'.$newMedicine->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($draft->fresh()->status)->toBe(MedicationOrderStatus::Pending)
        ->and($administeredOrder->fresh()->status)->toBe(MedicationOrderStatus::Administered);

    $this->assertDatabaseHas('medication_order_medicines', [
        'medication_order_id' => $draft->id,
        'medicine_id' => $newMedicine->id,
        'name' => 'New Medicine',
    ]);
});

test('doctor can save an order and call the next patient when the token follows the doctor', function () {
    [$user, $doctor, , $service, $queue, , $currentToken] = createMedicationQueuePatient(tokenStatus: 'serving', followsDoctorToken: true);
    $medicine = Medicine::factory()->create();
    $nextPatient = Patient::factory()->create(['name' => 'Next Patient']);

    ServicePrice::factory()->singleTokenDisplay()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
    ]);

    $nextToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $nextPatient->id,
        'token_number' => 2,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $currentToken->id)
        ->assertSee(__('Save & Next Patient'))
        ->set('medicationLines', [[
            'selection' => 'medicine:'.$medicine->id,
            'dose' => '1-0-0',
            'administration_type' => 'im',
            'comment' => '',
        ]])
        ->call('saveAndNext')
        ->assertHasNoErrors()
        ->assertSet('selectedTokenId', $nextToken->id)
        ->assertSee($nextPatient->name);

    expect($currentToken->fresh()->status)->toBe('served')
        ->and($nextToken->fresh()->status)->toBe('serving')
        ->and($nextToken->fresh()->displayed_at)->not->toBeNull();

    $this->assertDatabaseHas('medication_orders', [
        'queue_token_id' => $currentToken->id,
        'patient_id' => $currentToken->patient_id,
    ]);
});

test('medication services that do not follow the doctor cannot advance the display token', function () {
    [$user, $doctor, , $service, $queue, , $currentToken] = createMedicationQueuePatient(tokenStatus: 'serving');
    $medicine = Medicine::factory()->create();

    ServicePrice::factory()->singleTokenDisplay()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
    ]);

    $nextToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 2,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $currentToken->id)
        ->assertDontSee(__('Save & Next Patient'))
        ->set('medicationLines', [[
            'selection' => 'medicine:'.$medicine->id,
            'dose' => '1-0-0',
            'administration_type' => 'im',
            'comment' => '',
        ]])
        ->call('saveAndNext')
        ->assertForbidden();

    expect($currentToken->fresh()->status)->toBe('serving')
        ->and($nextToken->fresh()->status)->toBe('waiting');
});

test('doctor previews an order as an er slip before confirming it', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create(['name' => 'Preview Paracetamol']);
    $injection = Injection::factory()->create(['name' => 'Preview Diclofenac']);
    $visibleDrip = DripBase::factory()->create([
        'name' => 'Preview Saline',
        'show_on_er' => true,
    ]);
    $hiddenDrip = DripBase::factory()->create([
        'name' => 'Ward-only Saline',
        'show_on_er' => false,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicationLines', [
            [
                'selection' => 'medicine:'.$medicine->id,
                'dose' => '1-0-1',
                'administration_type' => 'im',
                'comment' => '',
            ],
            [
                'selection' => 'injection:'.$injection->id,
                'dose' => '1-0-0',
                'administration_type' => 'iv',
                'comment' => '',
            ],
        ])
        ->set('dripLines', [
            [
                'drip_base_id' => $visibleDrip->id,
                'additives' => [],
            ],
            [
                'drip_base_id' => $hiddenDrip->id,
                'additives' => [],
            ],
        ])
        ->set('notes', 'Give after food.')
        ->call('previewOrder')
        ->assertHasNoErrors()
        ->assertSet('showOrderPreviewModal', true)
        ->assertSee(__('ER order preview'))
        ->assertSee($patient->name)
        ->assertSee('Preview Paracetamol')
        ->assertSee('Preview Diclofenac')
        ->assertSee('Preview Saline')
        ->assertSee('Give after food.');

    expect(MedicationOrder::query()->where('queue_token_id', $token->id)->exists())->toBeFalse()
        ->and($component->get('orderPreview.drips'))->toHaveCount(1)
        ->and($component->get('orderPreview.drips.0.name'))->toBe('Preview Saline');

    $component
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showOrderPreviewModal', false);

    expect(MedicationOrder::query()->where('queue_token_id', $token->id)->exists())->toBeTrue();
});

test('only one medication modal is open at a time', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->call('openHistory')
        ->assertSet('showHistoryModal', true)
        ->set('medicationLines', [[
            'selection' => 'medicine:'.$medicine->id,
            'dose' => '1-0-0',
            'administration_type' => 'im',
            'comment' => '',
        ]])
        ->call('previewOrder')
        ->assertHasNoErrors()
        ->assertSet('showOrderPreviewModal', true)
        ->assertSet('showHistoryModal', false)
        ->call('openHistory')
        ->assertSet('showHistoryModal', true)
        ->assertSet('showOrderPreviewModal', false);
});

test('medication modals render with unique flux names', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSeeHtml('data-modal="medication-order-preview"')
        ->assertSeeHtml('data-modal="medication-history"');
});

test('medication queue keeps patients with an active recheck after an order is saved', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicationLines', [[
            'selection' => 'medicine:'.$medicine->id,
            'dose' => '1-0-0',
            'administration_type' => 'im',
            'comment' => '',
        ]])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDontSee($patient->name);

    DoctorRecheck::factory()->due()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $user->id,
        'note' => 'BP',
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->assertSee($patient->name)
        ->assertSee(__('Again'));
});

test('medication queue removes patients after recheck is acknowledged when order exists', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);

    MedicationOrder::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'status' => MedicationOrderStatus::Pending,
    ]);

    $recheck = DoctorRecheck::factory()->due()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->assertSee($patient->name)
        ->call('acknowledgeRecheck', $token->id)
        ->assertHasNoErrors()
        ->assertDontSee($patient->name);

    expect($recheck->fresh()->acknowledged_at)->not->toBeNull();
});

test('doctor can save a medication order for a standalone service without a doctor', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create(['name' => 'Paracetamol']);
    $injection = Injection::factory()->create(['name' => 'Diclofenac']);
    $additiveInjection = Injection::factory()->create(['name' => 'Vitamin B12']);
    $dripBase = DripBase::factory()->create(['name' => 'Normal Saline', 'default_volume_ml' => 100]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('complaintOrDiagnosis', 'Fever and body aches')
        ->set('medicationLines', [
            [
                'selection' => 'medicine:'.$medicine->id,
                'dose' => '1-0-1',
                'administration_type' => 'im',
                'comment' => 'Give after food',
            ],
            [
                'selection' => 'injection:'.$injection->id,
                'dose' => '1-0-0',
                'administration_type' => 'iv',
                'comment' => 'Give slowly',
            ],
        ])
        ->set('dripLines', [[
            'drip_base_id' => $dripBase->id,
            'additives' => [[
                'injection_id' => $additiveInjection->id,
            ]],
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $order = MedicationOrder::query()->where('queue_token_id', $token->id)->first();

    expect($order)->not->toBeNull()
        ->and($order->patient_id)->toBe($patient->id)
        ->and($order->doctor_id)->toBeNull()
        ->and($order->prescribed_by)->toBe($user->id)
        ->and($order->status)->toBe(MedicationOrderStatus::Pending)
        ->and($order->complaint_or_diagnosis)->toBe('Fever and body aches')
        ->and($order->medicines)->toHaveCount(1)
        ->and($order->injections)->toHaveCount(1)
        ->and($order->drips)->toHaveCount(1)
        ->and($order->drips->first()->additives)->toHaveCount(1);

    $this->assertDatabaseHas('medication_order_medicines', [
        'medication_order_id' => $order->id,
        'medicine_id' => $medicine->id,
        'dose' => '1-0-1',
        'comment' => 'Give after food',
        'name' => 'Paracetamol',
    ]);

    $this->assertDatabaseHas('medication_order_injections', [
        'medication_order_id' => $order->id,
        'injection_id' => $injection->id,
        'administration_type' => 'iv',
        'comment' => 'Give slowly',
        'name' => 'Diclofenac',
    ]);

    $this->assertDatabaseHas('medication_order_drip_additives', [
        'injection_id' => $additiveInjection->id,
        'name' => 'Vitamin B12',
    ]);
});

test('medication order keeps the queue doctor when the service has one', function () {
    [$user, $doctor, , , , , $token] = createMedicationQueuePatient();
    $medicine = Medicine::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicationLines', [[
            'selection' => 'medicine:'.$medicine->id,
            'dose' => '1-0-0',
            'administration_type' => 'im',
            'comment' => '',
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('medication_orders', [
        'queue_token_id' => $token->id,
        'doctor_id' => $doctor->id,
    ]);
});

test('medication form uses searchable selects for catalog fields', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);
    Medicine::factory()->create(['name' => 'Searchable Paracetamol', 'short_form' => 'PCM']);
    Injection::factory()->create(['name' => 'Searchable Diclofenac', 'short_form' => 'DIC']);
    DripBase::factory()->create(['name' => 'Searchable Saline']);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSee(__('Medications'))
        ->assertSee(__('Search medicine or injection'))
        ->assertSee(__('Medicine').' — PCM — Searchable Paracetamol')
        ->assertSee(__('Injection').' — DIC — Searchable Diclofenac')
        ->call('switchOrderTab', 'drips')
        ->assertSee(__('Search drip base'))
        ->assertSee('Searchable Saline');
});

test('medicines and injections are listed alphabetically', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);

    Medicine::factory()->create(['name' => 'Zinc']);
    Medicine::factory()->create(['name' => 'amoxicillin']);
    Medicine::factory()->create(['name' => 'Paracetamol']);
    Injection::factory()->create(['name' => 'Vitamin K']);
    Injection::factory()->create(['name' => 'diclofenac']);
    Injection::factory()->create(['name' => 'Ceftriaxone']);

    $component = Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id);

    expect($component->instance()->medicines->pluck('name')->all())
        ->toBe(['amoxicillin', 'Paracetamol', 'Zinc'])
        ->and($component->instance()->injections->pluck('name')->all())
        ->toBe(['Ceftriaxone', 'diclofenac', 'Vitamin K']);
});

test('doctor can switch the medication form between typing and visual modes', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);
    Medicine::factory()->create(['name' => 'Visual Paracetamol']);
    Injection::factory()->create(['name' => 'Visual Diclofenac']);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSee(__('Typing'))
        ->assertSee(__('Visual'))
        ->assertSee(__('Search medicine or injection'))
        ->set('orderInputMode', 'visual')
        ->assertDontSee(__('Search medicine or injection'))
        ->assertSee('Visual Paracetamol')
        ->assertSee('Visual Diclofenac')
        ->assertSee(__('Tap a medicine or injection above to add it.'))
        ->set('orderInputMode', 'typing')
        ->assertSee(__('Search medicine or injection'));
});

test('an unknown input mode falls back to typing', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('orderInputMode', 'nonsense')
        ->assertSet('orderInputMode', 'typing')
        ->assertSee(__('Search medicine or injection'));
});

test('visual badges toggle catalog medications with their default dose and administration type', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create([
        'name' => 'Badge Paracetamol',
        'default_dose' => MedicineDose::OneZeroOne,
    ]);
    $injection = Injection::factory()->create([
        'name' => 'Badge Diclofenac',
        'default_administration_type' => 'iv',
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('orderInputMode', 'visual')
        ->call('toggleMedicationSelection', 'medicine:'.$medicine->id)
        ->assertSet('medicationLines.0.selection', 'medicine:'.$medicine->id)
        ->assertSet('medicationLines.0.dose', '1-0-1')
        ->call('toggleMedicationSelection', 'injection:'.$injection->id)
        ->assertSet('medicationLines.1.selection', 'injection:'.$injection->id)
        ->assertSet('medicationLines.1.administration_type', 'iv')
        ->assertSee(__('Selected medications'));

    $component
        ->call('toggleMedicationSelection', 'medicine:'.$medicine->id)
        ->assertSet('medicationLines.0.selection', 'injection:'.$injection->id);

    $component->call('save')->assertHasNoErrors();

    $order = MedicationOrder::query()->where('queue_token_id', $token->id)->firstOrFail();

    expect($order->medicines)->toHaveCount(0)
        ->and($order->injections)->toHaveCount(1);

    $this->assertDatabaseHas('medication_order_injections', [
        'medication_order_id' => $order->id,
        'injection_id' => $injection->id,
        'administration_type' => 'iv',
        'name' => 'Badge Diclofenac',
    ]);
});

test('catalog defaults populate when a doctor selects a medicine or injection', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create([
        'default_dose' => MedicineDose::OneZeroOne,
    ]);
    $injection = Injection::factory()->create([
        'default_administration_type' => 'iv',
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicationLines.0.selection', 'medicine:'.$medicine->id)
        ->assertSet('medicationLines.0.dose', '1-0-1')
        ->assertSeeHtml('aria-label="'.__('Timing').'"')
        ->assertSee(__('Comment'))
        ->set('medicationLines.0.dose', '1-1-1')
        ->set('medicationLines.1.selection', 'injection:'.$injection->id)
        ->assertSet('medicationLines.1.administration_type', 'iv')
        ->assertSee(__('IM'))
        ->assertSee(__('IV'))
        ->assertSee(__('Comment'))
        ->set('medicationLines.1.administration_type', 'im')
        ->assertSet('medicationLines.1.administration_type', 'im');
});

test('doctor cannot add medicines to the catalog from the order form', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertDontSee(__('Medicine not listed? Add it'))
        ->assertDontSeeHtml('data-modal="medication-new-medicine"')
        ->assertSee(__('Search medicine or injection'));
});

test('doctor medication queue uses paper slips', function () {
    [$user, , , , , $patient] = createMedicationQueuePatient(withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->assertSee($patient->name)
        ->assertSee(__('Tap to prescribe'))
        ->assertSeeHtml('paper-slip');
});

test('medication form starts with common blank order rows', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertCount('medicationLines', 6)
        ->assertCount('dripLines', 1)
        ->assertCount('dripLines.0.additives', 2)
        ->assertSee(__('Medications'))
        ->call('switchOrderTab', 'drips')
        ->assertCount('dripLines', 1)
        ->call('addRowForActiveTab')
        ->assertCount('dripLines', 2)
        ->assertCount('dripLines.1.additives', 2)
        ->call('switchOrderTab', 'medicines')
        ->call('addRowForActiveTab')
        ->assertCount('medicationLines', 7);
});

test('blank default order rows are ignored when saving', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create(['name' => 'Only Filled Medicine']);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicationLines.0.selection', 'medicine:'.$medicine->id)
        ->set('medicationLines.0.dose', '1-0-1')
        ->call('save')
        ->assertHasNoErrors();

    $order = MedicationOrder::query()
        ->where('queue_token_id', $token->id)
        ->firstOrFail();

    expect($order->medicines)->toHaveCount(1)
        ->and($order->injections)->toHaveCount(0)
        ->and($order->drips)->toHaveCount(0);
});

test('doctor can open medication history modal for a patient', function () {
    [$user, , , , $queue, $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create(['name' => 'Amoxicillin']);

    $pastToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 42,
        'status' => 'served',
        'arrived_at' => now()->subDays(5),
    ]);

    $pastOrder = MedicationOrder::factory()->withoutDoctor()->administered($user)->create([
        'queue_token_id' => $pastToken->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'complaint_or_diagnosis' => 'Fever',
        'created_at' => now()->subDays(5),
    ]);

    $pastOrder->medicines()->create([
        'medicine_id' => $medicine->id,
        'dose' => MedicineDose::OneOneOne,
        'name' => 'Amoxicillin',
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->call('openHistory')
        ->assertSet('showHistoryModal', true)
        ->assertSee('Amoxicillin')
        ->assertSee('1-1-1')
        ->assertSee(__('Diagnosis').': Fever')
        ->call('closeHistory')
        ->assertSet('showHistoryModal', false);
});

test('medication history excludes the current visit order', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create(['name' => 'Only On Current Visit']);

    $component = Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id);

    $currentOrder = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
    ]);

    $currentOrder->medicines()->create([
        'medicine_id' => $medicine->id,
        'dose' => MedicineDose::OneZeroZero,
        'name' => 'Only On Current Visit',
    ]);

    $component
        ->call('openHistory')
        ->assertSee(__('No previous medication records'));

    expect($component->get('showHistoryModal'))->toBeTrue()
        ->and($component->instance()->medicationHistory)->toHaveCount(0);
});

test('doctor can write a medicine that is not in the catalog', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSee(__('Search medicine or injection'))
        ->set('medicationLines', [[
            'selection' => 'custom:Augmentin 625mg',
            'dose' => '1-0-1',
            'administration_type' => 'im',
            'comment' => '',
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $order = MedicationOrder::query()->where('queue_token_id', $token->id)->first();

    expect($order)->not->toBeNull()
        ->and($order->medicines)->toHaveCount(1);

    $this->assertDatabaseHas('medication_order_medicines', [
        'medication_order_id' => $order->id,
        'medicine_id' => null,
        'dose' => '1-0-1',
        'name' => 'Augmentin 625mg',
    ]);
});

test('doctor can write an injection and a drip additive that are not in the catalog', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);
    $dripBase = DripBase::factory()->create(['name' => 'Normal Saline']);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSee(__('Search medicine or injection'))
        ->set('medicationLines', [[
            'selection' => 'custom-injection:Ketorolac 30mg',
            'dose' => '1-0-0',
            'administration_type' => 'im',
            'comment' => '',
        ]])
        ->set('dripLines', [[
            'drip_base_id' => $dripBase->id,
            'additives' => [[
                'injection_id' => 'custom:Vitamin C 500mg',
            ]],
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $order = MedicationOrder::query()->where('queue_token_id', $token->id)->firstOrFail();

    expect($order->injections)->toHaveCount(1)
        ->and($order->drips->first()->additives)->toHaveCount(1);

    $this->assertDatabaseHas('medication_order_injections', [
        'medication_order_id' => $order->id,
        'injection_id' => null,
        'administration_type' => 'im',
        'name' => 'Ketorolac 30mg',
    ]);

    $this->assertDatabaseHas('medication_order_drip_additives', [
        'injection_id' => null,
        'name' => 'Vitamin C 500mg',
    ]);
});

test('reopening an order restores written injection names', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $dripBase = DripBase::factory()->create(['name' => 'Normal Saline']);

    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'status' => MedicationOrderStatus::Pending,
    ]);

    $order->injections()->create([
        'injection_id' => null,
        'administration_type' => 'im',
        'name' => 'Ketorolac 30mg',
    ]);

    $drip = $order->drips()->create([
        'drip_base_id' => $dripBase->id,
        'name' => 'Normal Saline',
    ]);

    $drip->additives()->create([
        'injection_id' => null,
        'name' => 'Vitamin C 500mg',
    ]);

    DoctorRecheck::factory()->due()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $user->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id);

    expect($component->get('medicationLines.0.selection'))->toBe('custom-injection:Ketorolac 30mg')
        ->and($component->get('dripLines.0.additives.0.injection_id'))->toBe('custom:Vitamin C 500mg');

    $component->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('medication_order_injections', [
        'medication_order_id' => $order->id,
        'injection_id' => null,
        'name' => 'Ketorolac 30mg',
    ]);
});

test('written injections appear in the er order preview', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicationLines', [[
            'selection' => 'custom-injection:Ketorolac 30mg',
            'dose' => '1-0-0',
            'administration_type' => 'iv',
            'comment' => '',
        ]])
        ->call('previewOrder')
        ->assertHasNoErrors()
        ->assertSet('showOrderPreviewModal', true)
        ->assertSee('Ketorolac 30mg');
});

test('order rows sit in one block and support alt arrow navigation', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);

    $component = Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id);

    $component
        ->assertSeeHtml('@keydown.alt.arrow-up.prevent')
        ->assertSeeHtml('@keydown.alt.arrow-down.prevent')
        ->assertSeeHtml('@keydown.alt.arrow-left.prevent')
        ->assertSeeHtml('@keydown.alt.arrow-right.prevent')
        ->assertSeeHtml('data-nav-row')
        ->assertSeeHtml('data-nav-field')
        ->assertDontSeeHtml('grid gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-12');

    $component
        ->assertSee(__('Search medicine or injection'))
        ->assertSeeHtml('data-nav-row')
        ->assertDontSeeHtml('grid gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-12');
});

test('a blank written injection name is rejected', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicationLines', [[
            'selection' => 'custom-injection:   ',
            'dose' => '1-0-0',
            'administration_type' => 'im',
            'comment' => '',
        ]])
        ->call('save')
        ->assertHasErrors('medicationLines.0.selection');

    expect(MedicationOrder::query()->where('queue_token_id', $token->id)->exists())->toBeFalse();
});

test('doctor can enter diagnosis as free text', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create(['name' => 'Paracetamol']);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSee(__('Diagnosis'))
        ->assertDontSee(__('Symptoms'))
        ->set('complaintOrDiagnosis', 'Pain')
        ->assertSee(__('Diagnosis').': Pain')
        ->set('medicationLines.0.selection', 'medicine:'.$medicine->id)
        ->call('save')
        ->assertHasNoErrors();

    $order = MedicationOrder::query()->where('queue_token_id', $token->id)->firstOrFail();

    expect($order->complaint_or_diagnosis)->toBe('Pain')
        ->and($order->symptoms)->toHaveCount(0)
        ->and($order->medicines)->toHaveCount(1);
});

test('existing diagnosis loads into the free text field', function () {
    [$user, , , , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create(['name' => 'Legacy Med']);

    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'status' => MedicationOrderStatus::Pending,
        'complaint_or_diagnosis' => 'Old free text complaint',
    ]);

    $order->medicines()->create([
        'medicine_id' => $medicine->id,
        'dose' => MedicineDose::OneZeroZero,
        'name' => 'Legacy Med',
    ]);

    DoctorRecheck::factory()->due()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSet('complaintOrDiagnosis', 'Old free text complaint')
        ->assertSee(__('Diagnosis').': Old free text complaint');
});

test('saved diagnosis appears as a badge in medication history', function () {
    [$user, , , , $queue, $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create(['name' => 'Paracetamol']);

    $pastToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 77,
        'status' => 'served',
        'arrived_at' => now()->subDay(),
    ]);

    $pastOrder = MedicationOrder::factory()->withoutDoctor()->administered($user)->create([
        'queue_token_id' => $pastToken->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'complaint_or_diagnosis' => 'Pain',
        'created_at' => now()->subDay(),
    ]);

    $pastOrder->medicines()->create([
        'medicine_id' => $medicine->id,
        'dose' => MedicineDose::OneZeroOne,
        'name' => 'Paracetamol',
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->call('openHistory')
        ->assertSee(__('Diagnosis').': Pain')
        ->assertDontSee(__('Complaint / diagnosis:'));
});
