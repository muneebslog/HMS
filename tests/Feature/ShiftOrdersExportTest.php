<?php

use App\Enums\DripLineStatus;
use App\Enums\InjectionAdministrationType;
use App\Enums\MedicationOrderStatus;
use App\Enums\MedicineDose;
use App\Enums\TokenResetType;
use App\Models\DripBase;
use App\Models\Family;
use App\Models\Injection;
use App\Models\MedicationOrder;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Services\ShiftOrdersExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: MedicationOrder, 1: Shift, 2: Patient}
 */
function createExportShiftOrder(
    ?Shift $shift = null,
    string $patientName = 'Export Patient',
    bool $withMedicine = true,
    bool $withInjection = false,
    bool $withDrip = false,
    ?Patient $patient = null,
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
    $patient ??= Patient::factory()->create(['name' => $patientName]);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => fake()->unique()->numberBetween(1, 99),
        'status' => 'waiting',
        'arrived_at' => $shift->opened_at,
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

    return [$order->fresh(['medicines', 'injections', 'drips.additives', 'patient.family']), $shift, $patient->fresh('family')];
}

test('shift orders page opens the export modal', function () {
    Shift::factory()->open()->create();

    Livewire::test('pages::display.shift-orders')
        ->assertSee(__('Export'))
        ->call('openExportModal')
        ->assertSet('showExportModal', true)
        ->assertSee(__('Export shift orders'))
        ->assertSee(__('All'))
        ->assertSee(__('Medicines'))
        ->assertSee(__('Injections'))
        ->assertSee(__('Drips'));
});

test('export page shows medicine patient for medicine filter', function () {
    [$order, $shift, $patient] = createExportShiftOrder(patientName: 'Med Patient');
    $order->medicines->first()->update(['delivered_at' => now()->setTime(14, 30)]);

    $this->get(route('display.shift_orders.export', [
        'shiftId' => $shift->id,
        'type' => ShiftOrdersExportService::TYPE_MEDICINE,
    ]))
        ->assertSuccessful()
        ->assertSee('Med Patient')
        ->assertSee($patient->mrn)
        ->assertSee('Paracetamol')
        ->assertSee('@ '.now()->setTime(14, 30)->format('Y-m-d').' 14:30');
});

test('injection filter hides medicine-only patients', function () {
    $shift = Shift::factory()->open()->create(['opened_at' => now()->subHour()]);
    createExportShiftOrder(shift: $shift, patientName: 'Medicine Only');
    createExportShiftOrder(shift: $shift, patientName: 'Injection Patient', withMedicine: false, withInjection: true);

    $this->get(route('display.shift_orders.export', [
        'shiftId' => $shift->id,
        'type' => ShiftOrdersExportService::TYPE_INJECTION,
    ]))
        ->assertSuccessful()
        ->assertSee('Injection Patient')
        ->assertDontSee('Medicine Only');
});

test('drip export shows start and end times', function () {
    [$order, $shift] = createExportShiftOrder(patientName: 'Drip Patient', withMedicine: false, withDrip: true);
    $startedAt = now()->subHours(2)->setSecond(0);
    $doneAt = now()->subHour()->setSecond(0);
    $order->drips->first()->update([
        'status' => DripLineStatus::Done,
        'started_at' => $startedAt,
        'done_at' => $doneAt,
    ]);

    $this->get(route('display.shift_orders.export', [
        'shiftId' => $shift->id,
        'type' => ShiftOrdersExportService::TYPE_DRIP,
    ]))
        ->assertSuccessful()
        ->assertSee('Drip Patient')
        ->assertSee('Normal Saline')
        ->assertSee($startedAt->format('Y-m-d H:i').'–'.$doneAt->format('Y-m-d H:i'));
});

test('phone linked shows yes when family phone exists and no otherwise', function () {
    $shift = Shift::factory()->open()->create(['opened_at' => now()->subHour()]);

    $withPhone = Patient::factory()->withPhone('03001234567')->create(['name' => 'Phone Yes']);
    createExportShiftOrder(shift: $shift, patient: $withPhone);

    $withoutPhone = Patient::factory()->create([
        'name' => 'Phone No',
        'family_id' => Family::factory()->withoutPhone()->create()->id,
    ]);
    createExportShiftOrder(shift: $shift, patient: $withoutPhone);

    $html = $this->get(route('display.shift_orders.export', [
        'shiftId' => $shift->id,
        'type' => ShiftOrdersExportService::TYPE_ALL,
    ]))->assertSuccessful()->getContent();

    expect($html)
        ->toContain('Phone Yes')
        ->toContain('Phone No');

    $yesPosition = strpos($html, 'Phone Yes');
    $noPosition = strpos($html, 'Phone No');
    $yesSlice = substr($html, $yesPosition, 400);
    $noSlice = substr($html, $noPosition, 400);

    expect($yesSlice)->toContain(__('Yes'));
    expect($noSlice)->toContain(__('No'));
});

test('two orders for the same patient collapse to one export row', function () {
    $shift = Shift::factory()->open()->create(['opened_at' => now()->subHour()]);
    $patient = Patient::factory()->create(['name' => 'Same Patient']);

    createExportShiftOrder(shift: $shift, patient: $patient, withMedicine: true);
    createExportShiftOrder(shift: $shift, patient: $patient, withMedicine: false, withInjection: true);

    $rows = app(ShiftOrdersExportService::class)->rowsForShift($shift, ShiftOrdersExportService::TYPE_ALL);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->patient_name)->toBe('Same Patient')
        ->and($rows->first()->items)->toContain('Paracetamol')
        ->and($rows->first()->items)->toContain('Diclofenac');
});

test('export uses the selected shift only', function () {
    $previous = Shift::factory()->closed()->create(['opened_at' => now()->subHours(8)]);
    $current = Shift::factory()->open()->create(['opened_at' => now()->subHour()]);

    createExportShiftOrder(shift: $previous, patientName: 'Other Shift Patient');
    createExportShiftOrder(shift: $current, patientName: 'Selected Shift Patient');

    $this->get(route('display.shift_orders.export', [
        'shiftId' => $current->id,
        'type' => ShiftOrdersExportService::TYPE_ALL,
    ]))
        ->assertSuccessful()
        ->assertSee('Selected Shift Patient')
        ->assertDontSee('Other Shift Patient');
});
