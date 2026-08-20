<?php

use App\Enums\DripChargeStatus;
use App\Enums\DripLineStatus;
use App\Enums\MedicineDose;
use App\Enums\PaymentMode;
use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\DripBase;
use App\Models\DripCharge;
use App\Models\Invoice;
use App\Models\MedicationOrder;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PrintJob;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Doctor, 2: Shift, 3: Service, 4: Service, 5: ServiceQueue, 6: Patient, 7: QueueToken}
 */
function createDripMedicationContext(): array
{
    $user = User::factory()->doctor()->create();
    $doctorProfile = Doctor::factory()->forUser($user)->create();
    $shift = Shift::factory()->for(User::factory()->receptionist())->open()->create();
    $consultation = Service::factory()->needsMedication()->create([
        'name' => 'General Checkup',
        'is_standalone' => false,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $dripService = Service::factory()->drip()->create([
        'name' => 'IV Drip',
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $queue = ServiceQueue::factory()->create([
        'service_id' => $consultation->id,
        'doctor_id' => $doctorProfile->id,
        'shift_id' => $shift->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'opened_at' => now(),
    ]);
    $patient = Patient::factory()->create(['name' => 'Drip Patient']);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'waiting',
        'arrived_at' => now()->subMinutes(5),
    ]);

    return [$user, $doctorProfile, $shift, $consultation, $dripService, $queue, $patient, $token];
}

test('authenticated users can create a drip service', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->set('serviceName', 'Custom Drip')
        ->set('serviceIsStandalone', true)
        ->set('serviceIsDrip', true)
        ->set('serviceTokenResetType', TokenResetType::Shift->value)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'name' => 'Custom Drip',
        'is_drip' => true,
    ]);
});

test('drip charge section only appears on the drips tab', function () {
    [$user, , , , , , , $token] = createDripMedicationContext();

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSet('activeOrderTab', 'medicines')
        ->assertDontSee(__('Drip charge'))
        ->assertDontSee(__('Suggested price'))
        ->call('switchOrderTab', 'drips')
        ->assertSee(__('Drip charge'))
        ->assertSee(__('Suggested price'));
});

test('doctor medication can suggest a drip price using logged-in doctor share', function () {
    [$user, $doctor, , , $dripService, , $patient, $token] = createDripMedicationContext();
    $medicine = Medicine::factory()->create();
    $dripBase = DripBase::factory()->create(['name' => 'Normal Saline']);

    ServicePrice::factory()->create([
        'service_id' => $dripService->id,
        'doctor_id' => $doctor->id,
        'price' => 500,
        'doctor_share' => 30,
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicationLines.0.selection', 'medicine:'.$medicine->id)
        ->set('medicationLines.0.dose', MedicineDose::OneZeroZero->value)
        ->set('dripLines', [[
            'drip_base_id' => $dripBase->id,
            'additives' => [['injection_id' => null]],
        ]])
        ->set('dripServiceId', $dripService->id)
        ->set('suggestedPrice', '750')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('drip_charges', [
        'patient_id' => $patient->id,
        'queue_token_id' => $token->id,
        'service_id' => $dripService->id,
        'doctor_id' => $doctor->id,
        'suggested_price' => 750,
        'doctor_share' => 30,
        'status' => DripChargeStatus::Pending->value,
        'suggested_by' => $user->id,
    ]);
});

test('doctor medication falls back to mo doctor when logged-in doctor has no share', function () {
    $user = User::factory()->doctor()->create();
    $loggedInDoctor = Doctor::factory()->create([
        'user_id' => $user->id,
        'name' => 'Dr Shareless',
    ]);
    $moDoctor = Doctor::factory()->create(['name' => 'mo']);

    $shift = Shift::factory()->for(User::factory()->receptionist())->open()->create();
    $consultation = Service::factory()->needsMedication()->create([
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $dripService = Service::factory()->drip()->create([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    ServicePrice::factory()->create([
        'service_id' => $dripService->id,
        'doctor_id' => $moDoctor->id,
        'price' => 400,
        'doctor_share' => 20,
    ]);

    $queue = ServiceQueue::factory()->create([
        'service_id' => $consultation->id,
        'doctor_id' => $loggedInDoctor->id,
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
    $medicine = Medicine::factory()->create();
    $dripBase = DripBase::factory()->create(['name' => 'Ringer']);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicationLines.0.selection', 'medicine:'.$medicine->id)
        ->set('medicationLines.0.dose', MedicineDose::OneZeroZero->value)
        ->set('dripLines', [[
            'drip_base_id' => $dripBase->id,
            'additives' => [['injection_id' => null]],
        ]])
        ->set('dripServiceId', $dripService->id)
        ->set('suggestedPrice', '600')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('drip_charges', [
        'patient_id' => $patient->id,
        'doctor_id' => $moDoctor->id,
        'doctor_share' => 20,
        'suggested_price' => 600,
        'status' => DripChargeStatus::Pending->value,
    ]);
});

test('walk-in shows pending drip charges and mark paid creates invoice and print job', function () {
    $receptionist = User::factory()->receptionist()->create();
    $shift = Shift::factory()->for($receptionist)->open()->create();
    $dripService = Service::factory()->drip()->create([
        'name' => 'IV Drip',
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $doctor = Doctor::factory()->create(['name' => 'mo']);
    $patient = Patient::factory()->create(['name' => 'Pending Drip Patient']);
    $suggester = User::factory()->doctor()->create();

    ServicePrice::factory()->create([
        'service_id' => $dripService->id,
        'doctor_id' => $doctor->id,
        'price' => 500,
        'doctor_share' => 25,
    ]);

    $charge = DripCharge::factory()->create([
        'patient_id' => $patient->id,
        'service_id' => $dripService->id,
        'doctor_id' => $doctor->id,
        'suggested_price' => 850,
        'doctor_share' => 25,
        'status' => DripChargeStatus::Pending,
        'suggested_by' => $suggester->id,
    ]);

    Livewire::actingAs($receptionist)
        ->test('pages::reception.walkin')
        ->assertSee('Pending Drip Patient')
        ->assertSee('850.00')
        ->call('openDripPay', $charge->id)
        ->assertSet('showDripPayModal', true)
        ->assertSet('dripPayPrice', '850')
        ->set('dripPaymentMode', PaymentMode::Cash->value)
        ->call('confirmDripPaid')
        ->assertHasNoErrors()
        ->assertDontSee('Pending Drip Patient');

    expect($charge->fresh()->status)->toBe(DripChargeStatus::Paid);
    expect($charge->fresh()->invoice_id)->not->toBeNull();

    $invoice = Invoice::find($charge->fresh()->invoice_id);

    expect($invoice)->not->toBeNull();
    expect($invoice->status)->toBe('paid');
    expect($invoice->total)->toBe(850.0);
    expect($invoice->shift_id)->toBe($shift->id);

    $this->assertDatabaseHas('invoice_items', [
        'invoice_id' => $invoice->id,
        'service_id' => $dripService->id,
        'doctor_id' => $doctor->id,
        'price' => 850,
        'doctor_share' => 25,
    ]);

    expect(PrintJob::where('invoice_id', $invoice->id)->exists())->toBeTrue();
    expect(QueueToken::whereHas('invoiceItem', fn ($query) => $query->where('invoice_id', $invoice->id))->exists())->toBeTrue();
});

test('ordering a drip without suggested price still sends a charge to reception', function () {
    [$user, , , , $dripService, , $patient, $token] = createDripMedicationContext();
    $dripBase = DripBase::factory()->create(['name' => 'Normal Saline']);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('dripLines', [[
            'drip_base_id' => $dripBase->id,
            'additives' => [['injection_id' => null]],
        ]])
        ->set('dripServiceId', $dripService->id)
        ->set('suggestedPrice', '')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('drip_charges', [
        'patient_id' => $patient->id,
        'queue_token_id' => $token->id,
        'service_id' => $dripService->id,
        'suggested_price' => null,
        'status' => DripChargeStatus::Pending->value,
    ]);
});

test('reception can set price for an unpriced drip and mark it paid', function () {
    $receptionist = User::factory()->receptionist()->create();
    Shift::factory()->for($receptionist)->open()->create();
    $dripService = Service::factory()->drip()->create([
        'name' => 'IV Drip',
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $patient = Patient::factory()->create(['name' => 'Needs Price Patient']);
    $suggester = User::factory()->doctor()->create();

    $charge = DripCharge::factory()->create([
        'patient_id' => $patient->id,
        'service_id' => $dripService->id,
        'doctor_id' => null,
        'suggested_price' => null,
        'doctor_share' => null,
        'status' => DripChargeStatus::Pending,
        'suggested_by' => $suggester->id,
    ]);

    Livewire::actingAs($receptionist)
        ->test('pages::reception.walkin')
        ->assertSee('Needs Price Patient')
        ->assertSee(__('Needs price'))
        ->call('openDripPay', $charge->id)
        ->assertSet('dripPayPrice', '')
        ->set('dripPayPrice', '900')
        ->set('dripPaymentMode', PaymentMode::Cash->value)
        ->call('confirmDripPaid')
        ->assertHasNoErrors()
        ->assertDontSee('Needs Price Patient');

    expect($charge->fresh()->status)->toBe(DripChargeStatus::Paid)
        ->and($charge->fresh()->suggested_price)->toBe(900.0);
});

test('reception can cancel a pending drip charge and active drip lines', function () {
    $receptionist = User::factory()->receptionist()->create();
    Shift::factory()->for($receptionist)->open()->create();
    $dripService = Service::factory()->drip()->create([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $patient = Patient::factory()->create(['name' => 'Cancel Drip Patient']);
    $suggester = User::factory()->doctor()->create();
    $order = MedicationOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => 'pending',
    ]);
    $drip = $order->drips()->create([
        'drip_base_id' => DripBase::factory()->create()->id,
        'name' => 'Normal Saline',
        'status' => DripLineStatus::Pending,
    ]);

    $charge = DripCharge::factory()->create([
        'patient_id' => $patient->id,
        'medication_order_id' => $order->id,
        'service_id' => $dripService->id,
        'suggested_price' => 500,
        'status' => DripChargeStatus::Pending,
        'suggested_by' => $suggester->id,
    ]);

    Livewire::actingAs($receptionist)
        ->test('pages::reception.walkin')
        ->assertSee('Cancel Drip Patient')
        ->call('cancelDrip', $charge->id)
        ->assertHasNoErrors()
        ->assertDontSee('Cancel Drip Patient');

    expect($charge->fresh()->status)->toBe(DripChargeStatus::Cancelled)
        ->and($drip->fresh()->status)->toBe(DripLineStatus::Cancelled);
});

test('suggested drip price without drip lines does not create a charge', function () {
    [$user, , , , $dripService, , $patient, $token] = createDripMedicationContext();
    $medicine = Medicine::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->set('medicationLines.0.selection', 'medicine:'.$medicine->id)
        ->set('medicationLines.0.dose', MedicineDose::OneZeroZero->value)
        ->set('dripServiceId', $dripService->id)
        ->set('suggestedPrice', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(DripCharge::where('patient_id', $patient->id)->exists())->toBeFalse();
});
