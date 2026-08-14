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
): array {
    $user = User::factory()->doctor()->create();
    $doctor = $withDoctor ? ($doctor ?? Doctor::factory()->create()) : null;
    $shift = Shift::factory()->open()->create();
    $service = Service::factory()->create([
        'name' => 'General Checkup',
        'is_standalone' => ! $withDoctor,
        'needs_medication' => $needsMedication,
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
        ->set('medicineLines', [[
            'medicine_id' => $medicine->id,
            'dose' => '1-0-0',
            'days' => '3',
        ]])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDontSee($patient->name);
});

test('doctor can save an order and call the next patient for a single-token queue', function () {
    [$user, $doctor, , $service, $queue, , $currentToken] = createMedicationQueuePatient(tokenStatus: 'serving');
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
        ->set('medicineLines', [[
            'medicine_id' => $medicine->id,
            'dose' => '1-0-0',
            'days' => '3',
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
        ->set('medicineLines', [[
            'medicine_id' => $medicine->id,
            'dose' => '1-0-1',
            'days' => '5',
        ]])
        ->set('injectionLines', [[
            'injection_id' => $injection->id,
            'administration_type' => 'iv',
            'volume_ml' => '3',
        ]])
        ->set('dripLines', [
            [
                'drip_base_id' => $visibleDrip->id,
                'volume_ml' => '100',
                'additives' => [],
            ],
            [
                'drip_base_id' => $hiddenDrip->id,
                'volume_ml' => '250',
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
        ->set('medicineLines', [[
            'medicine_id' => $medicine->id,
            'dose' => '1-0-0',
            'days' => '3',
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
        ->set('medicineLines', [[
            'medicine_id' => $medicine->id,
            'dose' => '1-0-0',
            'days' => '3',
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
        ->set('medicineLines', [[
            'medicine_id' => $medicine->id,
            'dose' => '1-0-1',
            'days' => '5',
        ]])
        ->set('injectionLines', [[
            'injection_id' => $injection->id,
            'administration_type' => 'iv',
            'volume_ml' => '3',
        ]])
        ->set('dripLines', [[
            'drip_base_id' => $dripBase->id,
            'volume_ml' => '100',
            'additives' => [[
                'injection_id' => $additiveInjection->id,
                'volume_ml' => '5',
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
        ->and($order->medicines)->toHaveCount(1)
        ->and($order->injections)->toHaveCount(1)
        ->and($order->drips)->toHaveCount(1)
        ->and($order->drips->first()->additives)->toHaveCount(1);

    $this->assertDatabaseHas('medication_order_medicines', [
        'medication_order_id' => $order->id,
        'medicine_id' => $medicine->id,
        'dose' => '1-0-1',
        'days' => 5,
        'name' => 'Paracetamol',
    ]);

    $this->assertDatabaseHas('medication_order_injections', [
        'medication_order_id' => $order->id,
        'injection_id' => $injection->id,
        'administration_type' => 'iv',
        'volume_ml' => 3,
        'name' => 'Diclofenac',
    ]);

    $this->assertDatabaseHas('medication_order_drip_additives', [
        'injection_id' => $additiveInjection->id,
        'volume_ml' => 5,
        'name' => 'Vitamin B12',
    ]);
});

test('medication order keeps the queue doctor when the service has one', function () {
    [$user, $doctor, , , , , $token] = createMedicationQueuePatient();
    $medicine = Medicine::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicineLines', [[
            'medicine_id' => $medicine->id,
            'dose' => '1-0-0',
            'days' => '3',
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
        ->assertSee(__('Search medicine or type a new name'))
        ->assertSee('PCM — Searchable Paracetamol')
        ->call('switchOrderTab', 'injections')
        ->assertSee(__('Search injection'))
        ->assertSee('DIC — Searchable Diclofenac')
        ->call('switchOrderTab', 'drips')
        ->assertSee(__('Search drip base'))
        ->assertSee('Searchable Saline');
});

test('doctor cannot add medicines to the catalog from the order form', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertDontSee(__('Medicine not listed? Add it'))
        ->assertDontSeeHtml('data-modal="medication-new-medicine"')
        ->assertSee(__('Search medicine or type a new name'));
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
        ->assertCount('medicineLines', 4)
        ->assertCount('injectionLines', 2)
        ->assertCount('dripLines', 1)
        ->assertCount('dripLines.0.additives', 2)
        ->call('switchOrderTab', 'injections')
        ->assertCount('injectionLines', 2)
        ->call('switchOrderTab', 'drips')
        ->assertCount('dripLines', 1)
        ->call('addRowForActiveTab')
        ->assertCount('dripLines', 2)
        ->assertCount('dripLines.1.additives', 2)
        ->call('switchOrderTab', 'medicines')
        ->call('addRowForActiveTab')
        ->assertCount('medicineLines', 5);
});

test('blank default order rows are ignored when saving', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);
    $medicine = Medicine::factory()->create(['name' => 'Only Filled Medicine']);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicineLines.0.medicine_id', $medicine->id)
        ->set('medicineLines.0.dose', '1-0-1')
        ->set('medicineLines.0.days', '5')
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
        'created_at' => now()->subDays(5),
    ]);

    $pastOrder->medicines()->create([
        'medicine_id' => $medicine->id,
        'dose' => MedicineDose::OneOneOne,
        'days' => 7,
        'name' => 'Amoxicillin',
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->call('openHistory')
        ->assertSet('showHistoryModal', true)
        ->assertSee('Amoxicillin')
        ->assertSee('1-1-1')
        ->assertSee('7')
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
        'days' => 2,
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
        ->assertSee(__('Search medicine or type a new name'))
        ->set('medicineLines', [[
            'medicine_id' => 'custom:Augmentin 625mg',
            'dose' => '1-0-1',
            'days' => '5',
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
        'days' => 5,
        'name' => 'Augmentin 625mg',
    ]);
});

test('doctor can write an injection and a drip additive that are not in the catalog', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);
    $dripBase = DripBase::factory()->create(['name' => 'Normal Saline']);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->call('switchOrderTab', 'injections')
        ->assertSee(__('Search injection or type a new name'))
        ->set('injectionLines', [[
            'injection_id' => 'custom:Ketorolac 30mg',
            'administration_type' => 'im',
            'volume_ml' => '2',
        ]])
        ->set('dripLines', [[
            'drip_base_id' => $dripBase->id,
            'volume_ml' => '100',
            'additives' => [[
                'injection_id' => 'custom:Vitamin C 500mg',
                'volume_ml' => '5',
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
        'volume_ml' => 2,
        'name' => 'Ketorolac 30mg',
    ]);

    $this->assertDatabaseHas('medication_order_drip_additives', [
        'injection_id' => null,
        'volume_ml' => 5,
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
        'volume_ml' => 2,
        'name' => 'Ketorolac 30mg',
    ]);

    $drip = $order->drips()->create([
        'drip_base_id' => $dripBase->id,
        'volume_ml' => 100,
        'name' => 'Normal Saline',
    ]);

    $drip->additives()->create([
        'injection_id' => null,
        'volume_ml' => 5,
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

    expect($component->get('injectionLines.0.injection_id'))->toBe('custom:Ketorolac 30mg')
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
        ->set('injectionLines', [[
            'injection_id' => 'custom:Ketorolac 30mg',
            'administration_type' => 'iv',
            'volume_ml' => '2',
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
        ->call('switchOrderTab', 'injections')
        ->assertSeeHtml('data-nav-row')
        ->assertDontSeeHtml('grid gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-12');
});

test('a blank written injection name is rejected', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('injectionLines', [[
            'injection_id' => 'custom:   ',
            'administration_type' => 'im',
            'volume_ml' => '2',
        ]])
        ->call('save')
        ->assertHasErrors('injectionLines.0.injection_id');

    expect(MedicationOrder::query()->where('queue_token_id', $token->id)->exists())->toBeFalse();
});
