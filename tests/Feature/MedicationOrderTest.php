<?php

use App\Enums\MedicationOrderStatus;
use App\Enums\MedicineDose;
use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\DripBase;
use App\Models\Injection;
use App\Models\MedicationOrder;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
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

test('receptionist can access medication admin with an open shift', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    $this->actingAs($user)
        ->get(route('reception.medication-admin'))
        ->assertSuccessful();
});

test('receptionist is redirected to shift page when accessing medication admin without an open shift', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('reception.medication-admin'))
        ->assertRedirect(route('reception.shift'));
});

test('reception sees pending orders and can mark them administered', function () {
    [$user, , $shift, , , $patient, $token] = createMedicationQueuePatient(withDoctor: false);
    $receptionist = User::factory()->receptionist()->create();
    $shift->update(['user_id' => $receptionist->id]);

    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
        'status' => MedicationOrderStatus::Pending,
    ]);

    Livewire::actingAs($receptionist)
        ->test('pages::reception.medication-admin')
        ->assertSee($patient->name)
        ->call('selectOrder', $order->id)
        ->call('markAdministered')
        ->assertHasNoErrors();

    $order->refresh();

    expect($order->status)->toBe(MedicationOrderStatus::Administered)
        ->and($order->administered_by)->toBe($receptionist->id)
        ->and($order->administered_at)->not->toBeNull();
});

test('medication form uses searchable selects for catalog fields', function () {
    [$user, , , , , , $token] = createMedicationQueuePatient(withDoctor: false);
    Medicine::factory()->create(['name' => 'Searchable Paracetamol']);
    Injection::factory()->create(['name' => 'Searchable Diclofenac']);
    DripBase::factory()->create(['name' => 'Searchable Saline']);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSee(__('Search medicine'))
        ->assertSee('Searchable Paracetamol')
        ->call('switchOrderTab', 'injections')
        ->call('addInjectionLine')
        ->assertSee(__('Search injection'))
        ->assertSee('Searchable Diclofenac')
        ->call('switchOrderTab', 'drips')
        ->call('addDripLine')
        ->assertSee(__('Search drip base'))
        ->assertSee('Searchable Saline');
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

    $component = Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->call('openHistory')
        ->assertSee(__('No previous medication records'));

    expect($component->get('showHistoryModal'))->toBeTrue()
        ->and($component->instance()->medicationHistory)->toHaveCount(0);
});
