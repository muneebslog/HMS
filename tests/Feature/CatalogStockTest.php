<?php

use App\Enums\DripLineStatus;
use App\Enums\InjectionAdministrationType;
use App\Enums\MedicationOrderStatus;
use App\Enums\MedicineDose;
use App\Enums\ProcedureMedicationDoseStatus;
use App\Enums\ProcedureMedicationForm;
use App\Enums\ProcedureMedicationScheduleType;
use App\Enums\StockLocation;
use App\Enums\TokenResetType;
use App\Models\DripBase;
use App\Models\HealthAide;
use App\Models\Injection;
use App\Models\MedicationOrder;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedureMedication;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use App\Services\ProcedureMedicationScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: MedicationOrder, 1: Medicine, 2: Injection}
 */
function createStockDeliveryOrder(): array
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
    $patient = Patient::factory()->create();
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'status' => MedicationOrderStatus::Pending,
    ]);

    $medicine = Medicine::factory()->withFrontStock(10)->create([
        'name' => 'Paracetamol',
    ]);
    $injection = Injection::factory()->withFrontStock(8)->create([
        'name' => 'Diclofenac',
    ]);

    $order->medicines()->create([
        'medicine_id' => $medicine->id,
        'dose' => MedicineDose::OneZeroOne,
        'name' => 'Paracetamol',
    ]);
    $order->injections()->create([
        'injection_id' => $injection->id,
        'administration_type' => InjectionAdministrationType::Im,
        'name' => 'Diclofenac',
    ]);
    $order->medicines()->create([
        'medicine_id' => null,
        'dose' => MedicineDose::OneZeroZero,
        'name' => 'Custom Free Text Med',
    ]);

    return [$order->fresh(['medicines', 'injections']), $medicine, $injection];
}

test('er delivery decrements front working stock and ignores free-text lines', function () {
    [$order, $medicine, $injection] = createStockDeliveryOrder();
    HealthAide::factory()->create(['pin' => '1234']);

    $catalogMedicine = $order->medicines->firstWhere('medicine_id', $medicine->id);
    $freeTextMedicine = $order->medicines->firstWhere('medicine_id', null);
    $catalogInjection = $order->injections->first();

    Livewire::test('pages::display.medication-delivery')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->call('selectOrder', $order->id)
        ->set('selectedMedicineIds', [$catalogMedicine->id, $freeTextMedicine->id])
        ->set('selectedInjectionIds', [$catalogInjection->id])
        ->call('requestNext')
        ->assertHasNoErrors();

    expect($medicine->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(9)
        ->and($injection->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(7)
        ->and($medicine->fresh()->stockBalance(StockLocation::BackStorage))->toBe(100);
});

test('starting a drip decrements front working drip base and additive injection stock', function () {
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
    $patient = Patient::factory()->create();
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 2,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'status' => MedicationOrderStatus::Pending,
    ]);

    $dripBase = DripBase::factory()->withFrontStock(5)->create([
        'name' => 'Normal Saline',
    ]);
    $additive = Injection::factory()->withFrontStock(4)->create([
        'name' => 'Vitamin B',
    ]);

    $drip = $order->drips()->create([
        'drip_base_id' => $dripBase->id,
        'name' => 'Normal Saline',
        'status' => DripLineStatus::Pending,
    ]);
    $drip->additives()->create([
        'injection_id' => $additive->id,
        'name' => 'Vitamin B',
    ]);

    HealthAide::factory()->create(['pin' => '1234']);

    Livewire::test('pages::display.drip-delivery')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->call('requestStart', $drip->id)
        ->assertHasNoErrors();

    expect($dripBase->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(4)
        ->and($additive->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(3)
        ->and($drip->fresh()->status)->toBe(DripLineStatus::Started);
});

test('marking a procedure dose given decrements front stock but skipped does not', function () {
    $user = User::factory()->indoor()->create();
    $procedure = Procedure::factory()->admitted()->create();
    $medicine = Medicine::factory()->withFrontStock(12)->create();
    $injection = Injection::factory()->withFrontStock(6)->create();

    Livewire::actingAs($user)
        ->test('pages::indoor.procedure', ['procedure' => $procedure])
        ->set('medForm', ProcedureMedicationForm::Tab->value)
        ->set('medMedicineId', $medicine->id)
        ->set('medDose', '500mg')
        ->set('medRoute', 'oral')
        ->set('medScheduleType', ProcedureMedicationScheduleType::OnceNow->value)
        ->call('prescribeMedication')
        ->assertHasNoErrors();

    $tabMedication = ProcedureMedication::query()
        ->where('procedure_id', $procedure->id)
        ->where('form', ProcedureMedicationForm::Tab)
        ->firstOrFail();
    $givenDose = $tabMedication->doses()->firstOrFail();

    Livewire::actingAs($user)
        ->test('pages::indoor.procedure', ['procedure' => $procedure])
        ->call('markDoseGiven', $givenDose->id)
        ->assertHasNoErrors();

    expect($medicine->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(11)
        ->and($givenDose->fresh()->status)->toBe(ProcedureMedicationDoseStatus::Given);

    $scheduler = app(ProcedureMedicationScheduler::class);
    $injMedication = ProcedureMedication::factory()->create([
        'procedure_id' => $procedure->id,
        'form' => ProcedureMedicationForm::Inj,
        'injection_id' => $injection->id,
        'medicine_id' => null,
        'schedule_type' => ProcedureMedicationScheduleType::OnceAt,
        'schedule_times' => ['16:00'],
        'prescribed_by' => $user->id,
    ]);
    $scheduler->materialize($injMedication, now()->setTime(10, 0));
    $skippedDose = $injMedication->doses()->firstOrFail();

    Livewire::actingAs($user)
        ->test('pages::indoor.procedure', ['procedure' => $procedure])
        ->call('markDoseSkipped', $skippedDose->id)
        ->assertHasNoErrors();

    expect($injection->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(6)
        ->and($skippedDose->fresh()->status)->toBe(ProcedureMedicationDoseStatus::Skipped);
});

test('management can set and update back stock on catalog items', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'medicines')
        ->call('create')
        ->set('medicineBulkRows.0.name', 'Stocked PCM')
        ->set('medicineBulkRows.0.unit', 'tablet')
        ->set('medicineBulkRows.0.stock_quantity', '25')
        ->call('save')
        ->assertHasNoErrors();

    $medicine = Medicine::query()->where('name', 'Stocked PCM')->firstOrFail();
    expect($medicine->stockBalance(StockLocation::BackStorage))->toBe(25);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'medicines')
        ->call('edit', $medicine->id)
        ->set('medicineStockQuantity', '40')
        ->call('save')
        ->assertHasNoErrors();

    expect($medicine->fresh()->stockBalance(StockLocation::BackStorage))->toBe(40);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'injections')
        ->call('create')
        ->set('injectionBulkRows.0.name', 'Stocked Inj')
        ->set('injectionBulkRows.0.stock_quantity', '15')
        ->call('save')
        ->assertHasNoErrors();

    $injection = Injection::query()->where('name', 'Stocked Inj')->firstOrFail();
    expect($injection->stockBalance(StockLocation::BackStorage))->toBe(15);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'dripBases')
        ->call('create')
        ->set('dripBaseName', 'Stocked NS')
        ->set('dripBaseDefaultVolumeMl', '500')
        ->set('dripBaseStockQuantity', '30')
        ->call('save')
        ->assertHasNoErrors();

    $dripBase = DripBase::query()->where('name', 'Stocked NS')->firstOrFail();
    expect($dripBase->stockBalance(StockLocation::BackStorage))->toBe(30);
});
