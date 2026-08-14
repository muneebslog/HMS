<?php

use App\Enums\DripLineStatus;
use App\Enums\InjectionAdministrationType;
use App\Enums\MedicationOrderStatus;
use App\Enums\MedicineDose;
use App\Enums\TokenResetType;
use App\Enums\UserRole;
use App\Models\DripBase;
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
            'days' => 5,
            'name' => 'Paracetamol',
        ]);
    }

    if ($withInjection) {
        $injection = Injection::factory()->create(['name' => 'Diclofenac']);
        $order->injections()->create([
            'injection_id' => $injection->id,
            'administration_type' => InjectionAdministrationType::Im,
            'volume_ml' => 3,
            'name' => 'Diclofenac',
        ]);
    }

    if ($withDrip) {
        $dripBase = DripBase::factory()->create(['name' => 'Normal Saline']);
        $order->drips()->create([
            'drip_base_id' => $dripBase->id,
            'volume_ml' => 100,
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

test('drip start sets thirty minute check due and can be marked done from kiosk', function () {
    [$order, , $patient] = createDeliveryOrderContext(withMedicine: false, withDrip: true);
    $aide = HealthAide::factory()->create(['pin' => '1234']);
    $drip = $order->drips->first();

    Carbon::setTestNow('2026-08-05 12:00:00');

    Livewire::test('pages::display.drip-delivery')
        ->assertSee($patient->name)
        ->assertSee(__('Token'))
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

test('reception medication admin route no longer exists', function () {
    expect(fn () => route('reception.medication-admin'))->toThrow(RouteNotFoundException::class);
});
