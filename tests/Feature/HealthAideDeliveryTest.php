<?php

use App\Enums\DripChargeStatus;
use App\Enums\DripLineStatus;
use App\Enums\InjectionAdministrationType;
use App\Enums\MedicationOrderStatus;
use App\Enums\MedicineDose;
use App\Enums\TokenResetType;
use App\Enums\UserRole;
use App\Models\DripBase;
use App\Models\DripCharge;
use App\Models\HealthAide;
use App\Models\Injection;
use App\Models\MedicationOrder;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use App\Services\HealthAidePinSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

uses(RefreshDatabase::class);

/**
 * @return array{0: MedicationOrder, 1: Shift, 2: Patient, 3: QueueToken}
 */
function createDeliveryOrderContext(bool $withMedicine = true, bool $withInjection = false, bool $withDrip = false): array
{
    $shift = Shift::factory()->open()->create();
    $service = Service::factory()->create([
        'needs_medication' => true,
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'shift_id' => $shift->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'opened_at' => now(),
    ]);
    $patient = Patient::factory()->create(['name' => 'Delivery Patient']);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 3,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'status' => MedicationOrderStatus::Pending,
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

    return [$order->fresh(['medicines', 'injections', 'drips']), $shift, $patient, $token];
}

test('medication delivery page is publicly accessible', function () {
    Shift::factory()->open()->create();

    $this->get(route('display.medication'))
        ->assertSuccessful();
});

test('drip delivery page is publicly accessible', function () {
    Shift::factory()->open()->create();

    $this->get(route('display.drips'))
        ->assertSuccessful();
});

test('medication delivery lists pending medicine lines and delivers with pin', function () {
    [$order, , $patient] = createDeliveryOrderContext(withMedicine: true, withInjection: true);
    $aide = HealthAide::factory()->create(['pin' => '1234', 'name' => 'Aide One']);
    $medicine = $order->medicines->first();
    $injection = $order->injections->first();

    Livewire::test('pages::display.medication-delivery')
        ->assertSee(__('ER Station'))
        ->assertSee($patient->name)
        ->assertSee(__('Token'))
        ->assertSee(__('Tap to deliver'))
        ->set('pin', '1234')
        ->call('verifyPin')
        ->assertHasNoErrors()
        ->call('selectOrder', $order->id)
        ->set('selectedMedicineIds', [$medicine->id])
        ->set('selectedInjectionIds', [$injection->id])
        ->call('requestNext')
        ->assertHasNoErrors();

    $medicine->refresh();
    $injection->refresh();
    $order->refresh();

    expect($medicine->delivered_at)->not->toBeNull()
        ->and($medicine->delivered_by_health_aide_id)->toBe($aide->id)
        ->and($injection->delivered_at)->not->toBeNull()
        ->and($injection->delivered_by_health_aide_id)->toBe($aide->id)
        ->and($order->status)->toBe(MedicationOrderStatus::Administered)
        ->and($order->administered_by_health_aide_id)->toBe($aide->id)
        ->and($order->administered_at)->not->toBeNull();
});

test('er station paper slips show medication order notes', function () {
    [$order] = createDeliveryOrderContext();
    $order->update(['notes' => "Give after food.\nWatch for dizziness."]);

    Livewire::test('pages::display.medication-delivery')
        ->assertSee(__('Notes:'))
        ->assertSee('Give after food.')
        ->call('selectOrder', $order->id)
        ->assertSee(__('Notes'))
        ->assertSee('Watch for dizziness.');
});

test('partial delivery does not mark order administered', function () {
    [$order] = createDeliveryOrderContext(withMedicine: true, withInjection: true);
    HealthAide::factory()->create(['pin' => '1234']);
    $medicine = $order->medicines->first();

    Livewire::test('pages::display.medication-delivery')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->call('selectOrder', $order->id)
        ->set('selectedMedicineIds', [$medicine->id])
        ->set('selectedInjectionIds', [])
        ->call('requestNext')
        ->assertHasNoErrors();

    $order->refresh();
    $medicine->refresh();

    expect($medicine->delivered_at)->not->toBeNull()
        ->and($order->injections->first()->delivered_at)->toBeNull()
        ->and($order->status)->toBe(MedicationOrderStatus::Pending);
});

test('drip-only orders do not appear on medication delivery page', function () {
    [, , $patient] = createDeliveryOrderContext(withMedicine: false, withInjection: false, withDrip: true);

    Livewire::test('pages::display.medication-delivery')
        ->assertDontSee($patient->name);
});

test('orders with unfinished drips stay unlocked so er can deliver medicines', function () {
    [$order, , $patient] = createDeliveryOrderContext(withMedicine: true, withDrip: true);
    $drip = $order->drips->first();

    Livewire::test('pages::display.medication-delivery')
        ->assertSee($patient->name)
        ->assertSee(__('Tap to deliver'))
        ->assertDontSee(__('Drip not yet given.'))
        ->assertDontSee(__('Not yet paid.'))
        ->call('selectOrder', $order->id)
        ->assertSet('selectedOrderId', $order->id);

    $drip->update(['status' => DripLineStatus::Started]);

    Livewire::test('pages::display.medication-delivery')
        ->assertSee($patient->name)
        ->assertSee(__('Tap to deliver'))
        ->assertDontSee(__('Drip not yet given.'));
});

test('unpaid drip charge locks er even when drips are unfinished', function () {
    [$order, , $patient] = createDeliveryOrderContext(withMedicine: true, withDrip: true);

    DripCharge::factory()->create([
        'patient_id' => $order->patient_id,
        'queue_token_id' => $order->queue_token_id,
        'medication_order_id' => $order->id,
        'status' => DripChargeStatus::Pending,
    ]);

    Livewire::test('pages::display.medication-delivery')
        ->assertSee($patient->name)
        ->assertSee(__('Not yet paid.'))
        ->assertDontSee(__('Drip not yet given.'))
        ->call('selectOrder', $order->id)
        ->assertSet('selectedOrderId', null);
});

test('er can deliver medicines while a drip is still running', function () {
    [$order] = createDeliveryOrderContext(withMedicine: true, withDrip: true);
    HealthAide::factory()->create(['pin' => '1234']);
    $medicine = $order->medicines->first();
    $drip = $order->drips->first();

    $drip->update(['status' => DripLineStatus::Started]);

    Livewire::test('pages::display.medication-delivery')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->call('selectOrder', $order->id)
        ->set('selectedMedicineIds', [$medicine->id])
        ->set('selectedInjectionIds', [])
        ->call('requestNext')
        ->assertHasNoErrors();

    expect($medicine->fresh()->delivered_at)->not->toBeNull()
        ->and($drip->fresh()->status)->toBe(DripLineStatus::Started)
        ->and($order->fresh()->status)->toBe(MedicationOrderStatus::Administered);
});

test('er slip shows only drips enabled in management', function () {
    [$order] = createDeliveryOrderContext(withMedicine: true, withDrip: true);
    $visibleDrip = $order->drips->first();
    $visibleDrip->dripBase->update(['show_on_er' => true]);
    $visibleDrip->update(['status' => DripLineStatus::Done, 'done_at' => now()]);

    $hiddenDripBase = DripBase::factory()->create([
        'name' => 'Hidden ER Drip',
        'show_on_er' => false,
    ]);
    $order->drips()->create([
        'drip_base_id' => $hiddenDripBase->id,
        'name' => 'Hidden ER Drip',
        'status' => DripLineStatus::Done,
        'done_at' => now(),
    ]);

    Livewire::test('pages::display.medication-delivery')
        ->assertSee(__('Drips'))
        ->call('selectOrder', $order->id)
        ->assertSee($visibleDrip->name)
        ->assertSee(__('Start and complete drips at the Drip Station.'))
        ->assertDontSee('Hidden ER Drip');
});

test('drip delivery shows all active drips for an order on one slip', function () {
    [$order, , $patient] = createDeliveryOrderContext(withMedicine: false, withDrip: true);
    $secondBase = DripBase::factory()->create(['name' => 'Ringer Lactate']);
    $order->drips()->create([
        'drip_base_id' => $secondBase->id,
        'name' => 'Ringer Lactate',
        'status' => DripLineStatus::Pending,
    ]);

    Livewire::test('pages::display.drip-delivery')
        ->assertSee($patient->name)
        ->assertSee('Normal Saline')
        ->assertSee('Ringer Lactate')
        ->assertSee(__('Start'))
        ->assertSee(__('End'))
        ->assertSeeHtml('wire:key="drip-delivery-'.$order->id.'"');
});

test('drip slip shows faded notes medicines and injections for context', function () {
    [$order, , $patient] = createDeliveryOrderContext(withMedicine: true, withInjection: true, withDrip: true);
    $order->update([
        'notes' => 'Keep patient warm',
        'complaint_or_diagnosis' => 'Dehydration',
    ]);

    Livewire::test('pages::display.drip-delivery')
        ->assertSee($patient->name)
        ->assertSee('Normal Saline')
        ->assertSee('Paracetamol')
        ->assertSee('Diclofenac')
        ->assertSee('Keep patient warm')
        ->assertSee('Dehydration')
        ->assertSee(__('Medicines'))
        ->assertSee(__('Injections'))
        ->assertSee(__('Notes'));
});

test('drip start sets thirty minute check due and can be marked done from kiosk', function () {
    [$order, , $patient] = createDeliveryOrderContext(withMedicine: false, withDrip: true);
    $aide = HealthAide::factory()->create(['pin' => '1234']);
    $drip = $order->drips->first();

    Carbon::setTestNow('2026-08-05 12:00:00');

    Livewire::test('pages::display.drip-delivery')
        ->assertSee($patient->name)
        ->assertSee(__('Token'))
        ->assertSee(__('Paid'))
        ->set('pin', '1234')
        ->call('verifyPin')
        ->call('requestStart', $drip->id)
        ->assertHasNoErrors();

    $drip->refresh();

    expect($drip->status)->toBe(DripLineStatus::Started)
        ->and($drip->started_by_health_aide_id)->toBe($aide->id)
        ->and($drip->check_due_at?->equalTo(Carbon::parse('2026-08-05 12:30:00')))->toBeTrue();

    Livewire::test('pages::display.drip-delivery')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->call('requestMarkDone', $drip->id)
        ->assertHasNoErrors();

    $drip->refresh();

    expect($drip->status)->toBe(DripLineStatus::Done)
        ->and($drip->done_by_health_aide_id)->toBe($aide->id)
        ->and($drip->done_at)->not->toBeNull();

    Carbon::setTestNow();
});

test('unpaid drip charge shows unpaid badge and blocks start at drip station', function () {
    [$order, , $patient] = createDeliveryOrderContext(withMedicine: false, withDrip: true);
    HealthAide::factory()->create(['pin' => '1234']);
    $drip = $order->drips->first();

    DripCharge::factory()->create([
        'patient_id' => $order->patient_id,
        'queue_token_id' => $order->queue_token_id,
        'medication_order_id' => $order->id,
        'status' => DripChargeStatus::Pending,
    ]);

    Livewire::test('pages::display.drip-delivery')
        ->assertSee($patient->name)
        ->assertSee(__('Unpaid'))
        ->set('pin', '1234')
        ->call('verifyPin')
        ->call('requestStart', $drip->id)
        ->assertHasNoErrors();

    expect($drip->fresh()->status)->toBe(DripLineStatus::Pending);
});

test('paid drip charge shows paid badge and allows start at drip station', function () {
    [$order, , $patient] = createDeliveryOrderContext(withMedicine: false, withDrip: true);
    HealthAide::factory()->create(['pin' => '1234']);
    $drip = $order->drips->first();

    DripCharge::factory()->paid()->create([
        'patient_id' => $order->patient_id,
        'queue_token_id' => $order->queue_token_id,
        'medication_order_id' => $order->id,
    ]);

    Livewire::test('pages::display.drip-delivery')
        ->assertSee($patient->name)
        ->assertSee(__('Paid'))
        ->assertDontSee(__('Unpaid'))
        ->set('pin', '1234')
        ->call('verifyPin')
        ->call('requestStart', $drip->id)
        ->assertHasNoErrors();

    expect($drip->fresh()->status)->toBe(DripLineStatus::Started);
});

test('admin can mark started drip done from recheck timers', function () {
    [$order] = createDeliveryOrderContext(withMedicine: false, withDrip: true);
    $aide = HealthAide::factory()->create();
    $drip = $order->drips->first();
    $drip->update([
        'status' => DripLineStatus::Started,
        'started_at' => now()->subMinutes(35),
        'started_by_health_aide_id' => $aide->id,
        'check_due_at' => now()->subMinutes(5),
    ]);

    $admin = User::factory()->create(['role' => UserRole::Admin]);

    Livewire::actingAs($admin)
        ->test('pages::admin.rechecks')
        ->assertSee($order->patient->name)
        ->assertSee($drip->name)
        ->call('markDripDone', $drip->id)
        ->assertHasNoErrors();

    $drip->refresh();

    expect($drip->status)->toBe(DripLineStatus::Done)
        ->and($drip->done_by_user_id)->toBe($admin->id)
        ->and($drip->done_at)->not->toBeNull();
});

test('drip page notifies once when check is due', function () {
    [$order] = createDeliveryOrderContext(withMedicine: false, withDrip: true);
    $aide = HealthAide::factory()->create();
    $drip = $order->drips->first();
    $drip->update([
        'status' => DripLineStatus::Started,
        'started_at' => now()->subMinutes(35),
        'started_by_health_aide_id' => $aide->id,
        'check_due_at' => now()->subMinute(),
        'check_notified_at' => null,
    ]);

    Livewire::test('pages::display.drip-delivery')
        ->call('notifyDueChecks');

    expect($drip->fresh()->check_notified_at)->not->toBeNull();
});

test('expired pin session requires re-entry before delivery', function () {
    [$order] = createDeliveryOrderContext();
    HealthAide::factory()->create(['pin' => '1234']);
    $medicine = $order->medicines->first();
    $session = app(HealthAidePinSession::class);

    Carbon::setTestNow('2026-08-05 10:00:00');
    $session->attempt('1234');

    Carbon::setTestNow('2026-08-05 10:11:00');

    Livewire::test('pages::display.medication-delivery')
        ->call('selectOrder', $order->id)
        ->set('selectedMedicineIds', [$medicine->id])
        ->call('requestNext')
        ->assertSet('showPinModal', true);

    expect($medicine->fresh()->delivered_at)->toBeNull();

    Carbon::setTestNow();
});

test('pending medication orders remain on er after the shift is closed', function () {
    [, $shift, $patient] = createDeliveryOrderContext();

    $shift->update([
        'status' => 'closed',
        'closed_at' => now(),
        'closing_balance' => 0,
    ]);

    Livewire::test('pages::display.medication-delivery')
        ->assertSee($patient->name)
        ->assertSee(__('Tap to deliver'));
});

test('pending medication orders remain on er after a new shift opens', function () {
    [, $shift, $patient, $token] = createDeliveryOrderContext();

    $shift->update([
        'status' => 'closed',
        'closed_at' => now(),
        'closing_balance' => 0,
    ]);
    $token->serviceQueue->update([
        'status' => 'closed',
        'closed_at' => now(),
    ]);
    Shift::factory()->open()->create();

    Livewire::test('pages::display.medication-delivery')
        ->assertSee(__('Previous shift'))
        ->assertDontSee(__('Current shift'))
        ->assertSee($patient->name);
});

test('er station groups current shift work separately from leftovers', function () {
    [$previousOrder, $previousShift, $previousPatient, $previousToken] = createDeliveryOrderContext();
    $previousPatient->update(['name' => 'Previous Shift Patient']);

    $previousShift->update([
        'status' => 'closed',
        'closed_at' => now(),
        'closing_balance' => 0,
    ]);
    $previousToken->serviceQueue->update([
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    [$currentOrder, , $currentPatient] = createDeliveryOrderContext();
    $currentPatient->update(['name' => 'Current Shift Patient']);

    $component = Livewire::test('pages::display.medication-delivery')
        ->assertSee(__('Current shift'))
        ->assertSee(__('Previous shift'))
        ->assertSee($currentPatient->name)
        ->assertSee($previousPatient->name);

    $sections = $component->instance()->sectionedQueueItems;

    expect($sections['current']->pluck('order.id')->all())->toContain($currentOrder->id)
        ->and($sections['previous']->pluck('order.id')->all())->toContain($previousOrder->id)
        ->and($sections['drips'])->toHaveCount(0);
});

test('er station puts orders with active drips in the drips section', function () {
    [$dripOrder, $shift, $dripPatient] = createDeliveryOrderContext(withMedicine: true, withDrip: true);
    $dripPatient->update(['name' => 'Drip Section Patient']);

    $service = Service::factory()->create([
        'needs_medication' => true,
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'shift_id' => $shift->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'opened_at' => now(),
    ]);
    $medPatient = Patient::factory()->create(['name' => 'Medicine Only Patient']);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $medPatient->id,
        'token_number' => 9,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);
    $medOrder = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $medPatient->id,
        'status' => MedicationOrderStatus::Pending,
    ]);
    $medicine = Medicine::factory()->create(['name' => 'Ibuprofen']);
    $medOrder->medicines()->create([
        'medicine_id' => $medicine->id,
        'dose' => MedicineDose::OneZeroOne,
        'name' => 'Ibuprofen',
    ]);

    $component = Livewire::test('pages::display.medication-delivery')
        ->assertSee(__('Current shift'))
        ->assertSee(__('Drips'))
        ->assertSee($dripPatient->name)
        ->assertSee($medPatient->name)
        ->assertSee(__('Start and finish drips at the Drip Station. Tap to deliver medicines.'));

    $sections = $component->instance()->sectionedQueueItems;

    expect($sections['drips']->pluck('order.id')->all())->toContain($dripOrder->id)
        ->and($sections['current']->pluck('order.id')->all())->toContain($medOrder->id)
        ->and($sections['current']->pluck('order.id')->all())->not->toContain($dripOrder->id);
});

test('health aides can deliver leftover medication after the shift is closed', function () {
    [$order, $shift] = createDeliveryOrderContext();
    HealthAide::factory()->create(['pin' => '1234']);
    $medicine = $order->medicines->first();

    $shift->update([
        'status' => 'closed',
        'closed_at' => now(),
        'closing_balance' => 0,
    ]);

    Livewire::test('pages::display.medication-delivery')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->call('selectOrder', $order->id)
        ->set('selectedMedicineIds', [$medicine->id])
        ->call('requestNext')
        ->assertHasNoErrors();

    expect($medicine->fresh()->delivered_at)->not->toBeNull()
        ->and($order->fresh()->status)->toBe(MedicationOrderStatus::Administered);
});

test('active drips remain on drip station after the shift is closed', function () {
    [, $shift, $patient] = createDeliveryOrderContext(withMedicine: false, withDrip: true);

    $shift->update([
        'status' => 'closed',
        'closed_at' => now(),
        'closing_balance' => 0,
    ]);

    Livewire::test('pages::display.drip-delivery')
        ->assertSee($patient->name)
        ->assertSee('Normal Saline');
});

test('health aides can start leftover drips after a new shift opens', function () {
    [$order, $shift, , $token] = createDeliveryOrderContext(withMedicine: false, withDrip: true);
    HealthAide::factory()->create(['pin' => '1234']);
    $drip = $order->drips->first();

    $shift->update([
        'status' => 'closed',
        'closed_at' => now(),
        'closing_balance' => 0,
    ]);
    $token->serviceQueue->update([
        'status' => 'closed',
        'closed_at' => now(),
    ]);
    Shift::factory()->open()->create();

    Livewire::test('pages::display.drip-delivery')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->call('requestStart', $drip->id)
        ->assertHasNoErrors();

    expect($drip->fresh()->status)->toBe(DripLineStatus::Started);
});

test('reception medication admin route no longer exists', function () {
    expect(fn () => route('reception.medication-admin'))->toThrow(RouteNotFoundException::class);
});

test('medication delivery lists syrups after tablets with syrup styling', function () {
    [$order] = createDeliveryOrderContext(withMedicine: false);
    $order->medicines()->create([
        'name' => 'Syp. Calpol',
        'dose' => MedicineDose::OneZeroOne,
    ]);
    $order->medicines()->create([
        'name' => 'Tab. Panadol',
        'dose' => MedicineDose::OneZeroZero,
    ]);

    Livewire::test('pages::display.medication-delivery')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->call('selectOrder', $order->id)
        ->assertSeeInOrder(['Tab. Panadol', 'Syp. Calpol'])
        ->assertSeeHtml('border-amber-300')
        ->assertSeeHtml('text-amber-600');
});

test('sorted medicines place syrups after regular medicines', function () {
    [$order] = createDeliveryOrderContext(withMedicine: false);

    $tablet = $order->medicines()->create([
        'name' => 'Tab. Panadol',
        'dose' => MedicineDose::OneZeroZero,
    ]);

    $syrup = $order->medicines()->create([
        'name' => 'Syp. Calpol',
        'dose' => MedicineDose::OneZeroOne,
    ]);

    $anotherTablet = $order->medicines()->create([
        'name' => 'Tab. Brufen',
        'dose' => MedicineDose::OneOneOne,
    ]);

    expect($order->fresh()->sortedMedicines()->pluck('id')->all())
        ->toBe([$tablet->id, $anotherTablet->id, $syrup->id]);
});
