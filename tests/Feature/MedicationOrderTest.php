<?php

use App\Enums\MedicationOrderStatus;
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
 * @return array{0: User, 1: Doctor, 2: Shift, 3: Service, 4: ServiceQueue, 5: Patient, 6: QueueToken}
 */
function createMedicationQueuePatient(
    bool $needsMedication = true,
    string $tokenStatus = 'waiting',
    ?User $doctorUser = null,
    ?Doctor $doctor = null,
): array {
    $doctorUser ??= User::factory()->doctor()->create();
    $doctor ??= Doctor::factory()->forUser($doctorUser)->create();
    $shift = Shift::factory()->open()->create();
    $service = Service::factory()->create([
        'needs_medication' => $needsMedication,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
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

    return [$doctorUser, $doctor, $shift, $service, $queue, $patient, $token];
}

test('doctors can visit the medication page', function () {
    $user = User::factory()->doctor()->create();
    Doctor::factory()->forUser($user)->create();

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

test('medication queue lists waiting tokens for services that need medication', function () {
    [$doctorUser, , , , , $patient, $token] = createMedicationQueuePatient();

    Livewire::actingAs($doctorUser)
        ->test('pages::doctor.medication')
        ->assertSee($patient->name)
        ->assertSee((string) $token->token_number);
});

test('medication queue excludes tokens for services that do not need medication', function () {
    [$doctorUser, , , , , $patient] = createMedicationQueuePatient(needsMedication: false);

    Livewire::actingAs($doctorUser)
        ->test('pages::doctor.medication')
        ->assertDontSee($patient->name)
        ->assertSee(__('No patients need medication'));
});

test('medication queue excludes tokens for another doctor', function () {
    [$doctorUser, , , , , $patient] = createMedicationQueuePatient();
    $otherUser = User::factory()->doctor()->create();
    Doctor::factory()->forUser($otherUser)->create();

    Livewire::actingAs($otherUser)
        ->test('pages::doctor.medication')
        ->assertDontSee($patient->name)
        ->assertSee(__('No patients need medication'));
});

test('doctor sees vitals for the selected token', function () {
    [$doctorUser, , , , , $patient, $token] = createMedicationQueuePatient();

    Vital::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'recorded_by' => User::factory()->receptionist()->create()->id,
        'temperature' => 98.6,
        'bp_systolic' => 120,
        'bp_diastolic' => 80,
    ]);

    Livewire::actingAs($doctorUser)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSee('98.6')
        ->assertSee('120')
        ->assertSee('80');
});

test('doctor can save a medication order with medicines injections and drip additives', function () {
    [$doctorUser, $doctor, , , , $patient, $token] = createMedicationQueuePatient();
    $medicine = Medicine::factory()->create(['name' => 'Paracetamol']);
    $injection = Injection::factory()->create(['name' => 'Diclofenac']);
    $additiveInjection = Injection::factory()->create(['name' => 'Vitamin B12']);
    $dripBase = DripBase::factory()->create(['name' => 'Normal Saline', 'default_volume_ml' => 100]);

    Livewire::actingAs($doctorUser)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicineLines', [[
            'medicine_id' => $medicine->id,
            'quantity' => '2',
            'dosage_instructions' => 'Twice daily',
        ]])
        ->set('injectionLines', [[
            'injection_id' => $injection->id,
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
        ->and($order->doctor_id)->toBe($doctor->id)
        ->and($order->status)->toBe(MedicationOrderStatus::Pending)
        ->and($order->medicines)->toHaveCount(1)
        ->and($order->injections)->toHaveCount(1)
        ->and($order->drips)->toHaveCount(1)
        ->and($order->drips->first()->additives)->toHaveCount(1);

    $this->assertDatabaseHas('medication_order_medicines', [
        'medication_order_id' => $order->id,
        'medicine_id' => $medicine->id,
        'quantity' => 2,
        'name' => 'Paracetamol',
    ]);

    $this->assertDatabaseHas('medication_order_drip_additives', [
        'injection_id' => $additiveInjection->id,
        'volume_ml' => 5,
        'name' => 'Vitamin B12',
    ]);
});

test('doctor cannot save an order for another doctors token', function () {
    [, , , , , , $token] = createMedicationQueuePatient();
    $otherUser = User::factory()->doctor()->create();
    Doctor::factory()->forUser($otherUser)->create();
    $medicine = Medicine::factory()->create();

    Livewire::actingAs($otherUser)
        ->test('pages::doctor.medication')
        ->set('selectedTokenId', $token->id)
        ->set('medicineLines', [[
            'medicine_id' => $medicine->id,
            'quantity' => '1',
            'dosage_instructions' => '',
        ]])
        ->call('save');

    $this->assertDatabaseMissing('medication_orders', [
        'queue_token_id' => $token->id,
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
    [$doctorUser, $doctor, $shift, , , $patient, $token] = createMedicationQueuePatient();
    $receptionist = User::factory()->receptionist()->create();
    $shift->update(['user_id' => $receptionist->id]);

    $order = MedicationOrder::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'prescribed_by' => $doctorUser->id,
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
