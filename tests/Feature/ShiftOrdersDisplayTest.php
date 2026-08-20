<?php

use App\Enums\DripChargeStatus;
use App\Enums\DripLineStatus;
use App\Enums\InjectionAdministrationType;
use App\Enums\MedicationOrderStatus;
use App\Enums\MedicineDose;
use App\Enums\TokenResetType;
use App\Models\DripBase;
use App\Models\DripCharge;
use App\Models\Injection;
use App\Models\MedicationOrder;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: MedicationOrder, 1: Shift, 2: Patient}
 */
function createShiftOrder(
    ?Shift $shift = null,
    string $patientName = 'Board Patient',
    bool $withMedicine = true,
    bool $withInjection = false,
    bool $withDrip = false,
    MedicationOrderStatus $status = MedicationOrderStatus::Pending,
    ?int $tokenNumber = null,
): array {
    $shift ??= Shift::factory()->open()->create(['opened_at' => now()->subHour()]);
    $service = Service::factory()->create([
        'needs_medication' => true,
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'shift_id' => $shift->id,
        'date' => $shift->opened_at->toDateString(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'opened_at' => $shift->opened_at,
    ]);
    $patient = Patient::factory()->create(['name' => $patientName]);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => $tokenNumber ?? fake()->unique()->numberBetween(1, 99),
        'status' => 'waiting',
        'arrived_at' => $shift->opened_at,
    ]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'status' => $status,
    ]);

    if ($withMedicine) {
        $medicine = Medicine::factory()->create(['name' => 'Paracetamol']);
        $order->medicines()->create([
            'medicine_id' => $medicine->id,
            'dose' => MedicineDose::OneZeroOne,
            'name' => 'Paracetamol',
        ]);
    }

    if ($withInjection) {
        $injection = Injection::factory()->create(['name' => 'Diclofenac']);
        $order->injections()->create([
            'injection_id' => $injection->id,
            'administration_type' => InjectionAdministrationType::Im,
            'name' => 'Diclofenac',
        ]);
    }

    if ($withDrip) {
        $dripBase = DripBase::factory()->create(['name' => 'Normal Saline']);
        $order->drips()->create([
            'drip_base_id' => $dripBase->id,
            'name' => 'Normal Saline',
            'status' => DripLineStatus::Pending,
        ]);
    }

    return [$order->fresh(['medicines', 'injections', 'drips']), $shift, $patient];
}

test('shift orders page is publicly accessible', function () {
    Shift::factory()->open()->create();

    $this->get(route('display.shift_orders'))
        ->assertSuccessful()
        ->assertSee(__('Shift Orders'));
});

test('shift orders lists current shift patients who have medication or drip orders', function () {
    [$order, , $patient] = createShiftOrder(patientName: 'Has Medicine');
    createShiftOrder(shift: $order->queueToken->serviceQueue->shift, patientName: 'Empty Order', withMedicine: false);

    Livewire::test('pages::display.shift-orders')
        ->assertSee('Has Medicine')
        ->assertDontSee('Empty Order')
        ->assertSee(__('Token'))
        ->assertSee($patient->name);
});

test('shift orders includes drip-only patients', function () {
    createShiftOrder(patientName: 'Drip Only', withMedicine: false, withDrip: true);

    Livewire::test('pages::display.shift-orders')
        ->assertSee('Drip Only');
});

test('shift orders hides recalled drafts', function () {
    createShiftOrder(patientName: 'Recalled Patient', status: MedicationOrderStatus::Draft);

    Livewire::test('pages::display.shift-orders')
        ->assertDontSee('Recalled Patient');
});

test('opening a slip shows given not given and waiting statuses', function () {
    [$order] = createShiftOrder(patientName: 'Status Patient', withMedicine: true, withDrip: true);
    $order->medicines->first()->update(['delivered_at' => now()]);
    $order->drips->first()->update(['status' => DripLineStatus::Started]);

    Livewire::test('pages::display.shift-orders')
        ->call('selectOrder', $order->id)
        ->assertSee('Paracetamol')
        ->assertSee(__('Given'))
        ->assertSee('Normal Saline')
        ->assertSee(__('Waiting'))
        ->assertDontSee(__('Tap to deliver'))
        ->assertDontSee(__('Enter PIN'));
});

test('medicines waiting on an unpaid drip show as waiting', function () {
    [$order] = createShiftOrder(patientName: 'Held Patient', withMedicine: true, withDrip: true);
    DripCharge::factory()->create([
        'patient_id' => $order->patient_id,
        'queue_token_id' => $order->queue_token_id,
        'medication_order_id' => $order->id,
        'status' => DripChargeStatus::Pending,
    ]);

    Livewire::test('pages::display.shift-orders')
        ->call('selectOrder', $order->id)
        ->assertSee('Paracetamol')
        ->assertSee(__('Waiting'))
        ->assertSee(__('Not given'));
});

test('previous and next shift navigation shows that shift orders only', function () {
    $previous = Shift::factory()->closed()->create(['opened_at' => now()->subHours(8)]);
    $current = Shift::factory()->open()->create(['opened_at' => now()->subHour()]);

    createShiftOrder(shift: $previous, patientName: 'Yesterday Patient');
    createShiftOrder(shift: $current, patientName: 'Today Patient');

    Livewire::test('pages::display.shift-orders')
        ->assertSee('Today Patient')
        ->assertDontSee('Yesterday Patient')
        ->call('goToPreviousShift')
        ->assertSee('Yesterday Patient')
        ->assertDontSee('Today Patient')
        ->call('goToNextShift')
        ->assertSee('Today Patient')
        ->assertDontSee('Yesterday Patient');
});

test('shift orders link appears in the system sidebar', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Shift Orders'))
        ->assertSee(route('display.shift_orders'), false);
});

test('shift orders shows every slip without pagination', function () {
    $shift = Shift::factory()->open()->create(['opened_at' => now()->subHour()]);

    foreach (range(1, 12) as $number) {
        createShiftOrder(shift: $shift, patientName: 'Patient '.$number);
    }

    $component = Livewire::test('pages::display.shift-orders');

    foreach (range(1, 12) as $number) {
        $component->assertSee('Patient '.$number);
    }
});

test('shift orders lists slips by token number descending', function () {
    $shift = Shift::factory()->open()->create(['opened_at' => now()->subHour()]);

    createShiftOrder(shift: $shift, patientName: 'Low Token', tokenNumber: 2);
    createShiftOrder(shift: $shift, patientName: 'High Token', tokenNumber: 15);
    createShiftOrder(shift: $shift, patientName: 'Mid Token', tokenNumber: 8);

    $names = Livewire::test('pages::display.shift-orders')
        ->instance()
        ->orders
        ->pluck('patient.name')
        ->all();

    expect($names)->toBe(['High Token', 'Mid Token', 'Low Token']);
});
