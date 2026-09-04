<?php

use App\Enums\ProcedureStatus;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedurePayment;
use App\Models\ProcedureType;
use App\Models\ProcedureTypeDocument;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.procedures'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the procedures page', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.procedures'));

    $response->assertOk();
});

test('a procedure with patient details and advance payment can be created', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $doctor = Doctor::factory()->create();
    $procedureType = ProcedureType::factory()->create(['name' => 'Normal Delivery']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('husbandName', 'James Doe')
        ->set('patientAge', 30)
        ->set('procedureTypeId', $procedureType->id)
        ->set('expectedDeliveryDate', '2026-12-15')
        ->set('fullAmount', '5000')
        ->set('doctorId', $doctor->id)
        ->set('hasAdvancePayment', true)
        ->set('advancePayment', '2000')
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $patient = Patient::where('name', 'John Doe')->first();
    expect($patient)->not->toBeNull()
        ->husband_name->toBe('James Doe')
        ->age->toBe(30)
        ->gender->toBe('female');
    expect($patient->contactPhone())->toBe('03001234567');

    $procedure = Procedure::where('patient_id', $patient->id)->first();
    expect($procedure)->not->toBeNull()
        ->name->toBe('Normal Delivery')
        ->procedure_type_id->toBe($procedureType->id)
        ->full_amount->toBe(5000.0)
        ->doctor_id->toBe($doctor->id)
        ->status->value->toBe('booking')
        ->and($procedure->expected_delivery_date->format('Y-m-d'))->toBe('2026-12-15');

    expect($procedure->payments)->toHaveCount(1)
        ->and($procedure->payments->first())
        ->amount->toBe(2000.0)
        ->mode->value->toBe('cash');

    expect($procedure->totalPaid())->toBe(2000.0)
        ->and($procedure->balance())->toBe(3000.0)
        ->and($procedure->isPaid())->toBeFalse();
});

test('a procedure advance payment can be recorded as online', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $procedureType = ProcedureType::factory()->create(['name' => 'Normal Delivery']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('husbandName', 'James Doe')
        ->set('patientAge', 30)
        ->set('procedureTypeId', $procedureType->id)
        ->set('expectedDeliveryDate', '2026-12-15')
        ->set('fullAmount', '5000')
        ->set('hasAdvancePayment', true)
        ->set('advancePayment', '2000')
        ->set('advancePaymentMode', 'online')
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $procedure = Procedure::first();
    expect($procedure->payments)->toHaveCount(1)
        ->and($procedure->payments->first()->mode->value)->toBe('online');
});

test('a procedure can be created without advance payment when checkbox is unchecked', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $procedureType = ProcedureType::factory()->create(['name' => 'C-Section Package']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'Jane Doe')
        ->set('patientPhone', '03009876543')
        ->set('husbandName', 'John Doe')
        ->set('patientAge', 25)
        ->set('procedureTypeId', $procedureType->id)
        ->set('expectedDeliveryDate', '2026-11-01')
        ->set('fullAmount', '1000')
        ->set('doctorId', '')
        ->set('hasAdvancePayment', false)
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $procedure = Procedure::first();
    expect($procedure)->doctor_id->toBeNull()
        ->and($procedure->name)->toBe('C-Section Package')
        ->and($procedure->procedure_type_id)->toBe($procedureType->id)
        ->and($procedure->payments)->toHaveCount(0)
        ->and($procedure->balance())->toBe(1000.0)
        ->and($procedure->patient->husband_name)->toBe('John Doe');
});

test('a procedure can be created without a doctor', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $procedureType = ProcedureType::factory()->create(['name' => 'General Checkup']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'Jane Doe')
        ->set('patientPhone', '03009876543')
        ->set('husbandName', 'John Doe')
        ->set('patientAge', 25)
        ->set('procedureTypeId', $procedureType->id)
        ->set('expectedDeliveryDate', '2026-10-01')
        ->set('fullAmount', '1000')
        ->set('doctorId', '')
        ->set('hasAdvancePayment', false)
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $procedure = Procedure::first();
    expect($procedure)->doctor_id->toBeNull()
        ->and($procedure->payments)->toHaveCount(0)
        ->and($procedure->balance())->toBe(1000.0);
});

test('procedure type is required when creating a procedure', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'Jane Doe')
        ->set('patientPhone', '03009876543')
        ->set('husbandName', 'John Doe')
        ->set('patientAge', 25)
        ->set('procedureTypeId', null)
        ->set('expectedDeliveryDate', '2026-10-01')
        ->set('fullAmount', '1000')
        ->set('hasAdvancePayment', false)
        ->call('saveProcedure')
        ->assertHasErrors(['procedureTypeId']);

    expect(Procedure::count())->toBe(0);
});

test('inactive procedure types are not available in procedures', function () {
    $user = User::factory()->create();
    $activeType = ProcedureType::factory()->create(['name' => 'Active Type']);
    $inactiveType = ProcedureType::factory()->inactive()->create(['name' => 'Inactive Type']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->assertSet('procedureTypes', function ($types) use ($activeType, $inactiveType) {
            return $types->contains('id', $activeType->id)
                && ! $types->contains('id', $inactiveType->id);
        });
});

test('an open shift is required to create a procedure', function () {
    $user = User::factory()->create();
    $procedureType = ProcedureType::factory()->create(['name' => 'Appendectomy']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('husbandName', 'James Doe')
        ->set('patientAge', 30)
        ->set('procedureTypeId', $procedureType->id)
        ->set('expectedDeliveryDate', '2026-12-15')
        ->set('fullAmount', '5000')
        ->set('hasAdvancePayment', true)
        ->set('advancePayment', '2000')
        ->call('saveProcedure')
        ->assertHasNoErrors();

    expect(Procedure::count())->toBe(0)
        ->and(Patient::count())->toBe(0);
});

test('additional payments can be added to a procedure', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create(['full_amount' => 5000]);
    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 2000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('addPayment', $procedure->id)
        ->set('paymentAmount', '2500')
        ->call('savePayment')
        ->assertHasNoErrors();

    $procedure->refresh();
    expect($procedure->payments)->toHaveCount(2)
        ->and($procedure->totalPaid())->toBe(4500.0)
        ->and($procedure->balance())->toBe(500.0)
        ->and($procedure->isPaid())->toBeFalse()
        ->and($procedure->payments->last()->mode->value)->toBe('cash');
});

test('a procedure payment can be recorded as online', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create(['full_amount' => 5000]);
    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 2000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('addPayment', $procedure->id)
        ->set('paymentAmount', '2500')
        ->set('paymentMode', 'online')
        ->call('savePayment')
        ->assertHasNoErrors();

    $procedure->refresh();
    expect($procedure->payments)->toHaveCount(2)
        ->and($procedure->payments->last()->mode->value)->toBe('online');
});

test('a final payment marks the procedure as paid', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create(['full_amount' => 5000]);
    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 4000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('addPayment', $procedure->id)
        ->set('paymentAmount', '1000')
        ->call('savePayment')
        ->assertHasNoErrors();

    $procedure->refresh();
    expect($procedure->totalPaid())->toBe(5000.0)
        ->and($procedure->balance())->toBe(0.0)
        ->and($procedure->isPaid())->toBeTrue();
});

test('procedure maternity fields can be updated without changing type or package', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->withPhone('03009876543')->create([
        'name' => 'Jane Doe',
        'husband_name' => 'John Doe',
        'age' => 28,
    ]);
    $originalType = ProcedureType::factory()->create(['name' => 'Normal Delivery']);
    $updatedType = ProcedureType::factory()->create(['name' => 'C-Section Package']);
    $procedure = Procedure::factory()->for($shift)->for($patient)->create([
        'procedure_type_id' => $originalType->id,
        'name' => 'Normal Delivery',
        'full_amount' => 8000,
        'expected_delivery_date' => '2026-10-01',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('edit', $procedure->id)
        ->assertDontSee(__('Procedure name'))
        ->assertDontSee(__('Total package'))
        ->set('husbandName', 'Robert Doe')
        ->set('expectedDeliveryDate', '2026-11-20')
        ->set('procedureTypeId', $updatedType->id)
        ->set('fullAmount', '12000')
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $procedure->refresh();
    expect($procedure->name)->toBe('Normal Delivery')
        ->and($procedure->procedure_type_id)->toBe($originalType->id)
        ->and($procedure->full_amount)->toBe(8000.0)
        ->and($procedure->expected_delivery_date->format('Y-m-d'))->toBe('2026-11-20')
        ->and($procedure->patient->fresh()->husband_name)->toBe('Robert Doe');
});

test('editing a procedure does not show phone intake and cannot reassign the patient', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $linkedPatient = Patient::factory()->withPhone('03001112222')->create([
        'name' => 'Linked Patient',
        'husband_name' => 'Husband A',
        'age' => 28,
    ]);
    $otherPatient = Patient::factory()->withPhone('03003334444')->create([
        'name' => 'Other Patient',
        'husband_name' => 'Husband B',
        'age' => 32,
    ]);
    $procedure = Procedure::factory()->for($shift)->for($linkedPatient)->create([
        'full_amount' => 5000,
        'expected_delivery_date' => '2026-12-01',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('edit', $procedure->id)
        ->assertDontSee(__('Phone number'))
        ->assertDontSee(__('Have no number'))
        ->assertDontSee(__('Matching patients'))
        ->assertSee(__('Linked patient'))
        ->assertSee('03001112222')
        ->assertSee($linkedPatient->mrn)
        ->call('selectMatchedPatient', $otherPatient->id)
        ->assertHasErrors(['selectedPatientId'])
        ->assertSet('selectedPatientId', $linkedPatient->id)
        ->set('patientName', 'Updated Linked Name')
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $procedure->refresh();
    expect($procedure->patient_id)->toBe($linkedPatient->id)
        ->and($linkedPatient->fresh()->name)->toBe('Updated Linked Name')
        ->and($linkedPatient->fresh()->contactPhone())->toBe('03001112222')
        ->and($otherPatient->fresh()->name)->toBe('Other Patient');
});

test('clearing or replacing the patient while editing a procedure shows an error', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->withPhone('03005556666')->create([
        'name' => 'Ada Patient',
        'husband_name' => 'Husband',
        'age' => 30,
    ]);
    $procedure = Procedure::factory()->for($shift)->for($patient)->create([
        'full_amount' => 4000,
        'expected_delivery_date' => '2026-11-01',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('edit', $procedure->id)
        ->assertDontSee(__('Phone number'))
        ->call('clearSelectedPatient')
        ->assertHasErrors(['selectedPatientId'])
        ->assertSet('selectedPatientId', $patient->id)
        ->call('addNewFamilyMember')
        ->assertHasErrors(['selectedPatientId'])
        ->assertSet('patientName', 'Ada Patient');
});

test('payment amount cannot exceed the remaining balance', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create([
        'full_amount' => 5000,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('addPayment', $procedure->id)
        ->set('paymentAmount', '6000')
        ->call('savePayment')
        ->assertHasNoErrors();

    expect(ProcedurePayment::count())->toBe(0);
});

test('advance payment cannot exceed the full amount', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $procedureType = ProcedureType::factory()->create(['name' => 'Appendectomy']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('husbandName', 'James Doe')
        ->set('patientAge', 30)
        ->set('procedureTypeId', $procedureType->id)
        ->set('expectedDeliveryDate', '2026-12-15')
        ->set('fullAmount', '5000')
        ->set('hasAdvancePayment', true)
        ->set('advancePayment', '6000')
        ->call('saveProcedure')
        ->assertHasErrors(['advancePayment']);

    expect(Procedure::count())->toBe(0);
});

test('advance amount is required when advance checkbox is checked', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $procedureType = ProcedureType::factory()->create(['name' => 'Normal Delivery']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('husbandName', 'James Doe')
        ->set('patientAge', 30)
        ->set('procedureTypeId', $procedureType->id)
        ->set('expectedDeliveryDate', '2026-12-15')
        ->set('fullAmount', '5000')
        ->set('hasAdvancePayment', true)
        ->set('advancePayment', '')
        ->call('saveProcedure')
        ->assertHasErrors(['advancePayment']);

    expect(Procedure::count())->toBe(0);
});

test('procedures are listed with correct totals and status', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->create(['name' => 'Sara Khan']);
    $procedure = Procedure::factory()->for($shift)->for($patient)->create([
        'name' => 'Knee Surgery',
        'full_amount' => 10000,
        'expected_delivery_date' => '2026-12-01',
    ]);
    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 4000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->assertSee('Knee Surgery')
        ->assertSee('Sara Khan')
        ->assertSee($patient->mrn)
        ->assertSee('10,000.00')
        ->assertSee('4,000.00')
        ->assertSee('6,000.00')
        ->assertSee('Pending');
});

test('procedures can be searched by patient name and mrn', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();

    $matchingPatient = Patient::factory()->create(['name' => 'Ayesha Ali']);
    $otherPatient = Patient::factory()->create(['name' => 'Fatima Noor']);

    Procedure::factory()->for($shift)->for($matchingPatient)->create(['name' => 'Delivery A']);
    Procedure::factory()->for($shift)->for($otherPatient)->create(['name' => 'Delivery B']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('search', 'Ayesha')
        ->assertSee('Delivery A')
        ->assertDontSee('Delivery B');

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('search', $matchingPatient->mrn)
        ->assertSee('Delivery A')
        ->assertDontSee('Delivery B');
});

test('procedures page defaults to the last three days with admitted patients first', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();

    $recent = Procedure::factory()->for($shift)->create([
        'name' => 'Recent Delivery',
        'created_at' => now()->subDay(),
    ]);
    $old = Procedure::factory()->for($shift)->create([
        'name' => 'Old Delivery',
        'created_at' => now()->subDays(5),
    ]);
    $admittedRecent = Procedure::factory()->for($shift)->admitted()->create([
        'name' => 'Admitted Delivery',
        'created_at' => now()->subHours(2),
    ]);
    $admittedOlder = Procedure::factory()->for($shift)->admitted()->create([
        'name' => 'Long Stay Delivery',
        'created_at' => now()->subDays(10),
        'admitted_at' => now()->subDays(9),
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->assertSet('days', 3)
        ->assertSee('Recent Delivery')
        ->assertSee('Admitted Delivery')
        ->assertSee('Long Stay Delivery')
        ->assertDontSee('Old Delivery');

    $ids = $component->instance()->procedures->pluck('id')->all();

    expect($ids[0])->toBe($admittedRecent->id)
        ->and($ids)->toContain($admittedOlder->id)
        ->and($ids)->toContain($recent->id)
        ->and($ids)->not->toContain($old->id)
        ->and(array_search($admittedOlder->id, $ids))->toBeLessThan(array_search($recent->id, $ids));
});

test('procedures day window can be expanded to show older procedures', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();

    Procedure::factory()->for($shift)->create([
        'name' => 'Five Day Delivery',
        'created_at' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->assertDontSee('Five Day Delivery')
        ->call('setDays', 7)
        ->assertSet('days', 7)
        ->assertSee('Five Day Delivery')
        ->call('setDays', 0)
        ->assertSet('days', 0)
        ->assertSee('Five Day Delivery');
});

test('search finds procedures outside the day window', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->create(['name' => 'Older Patient']);

    Procedure::factory()->for($shift)->for($patient)->create([
        'name' => 'Archived Delivery',
        'created_at' => now()->subDays(20),
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->assertDontSee('Archived Delivery')
        ->set('search', 'Older Patient')
        ->assertSee('Archived Delivery');
});

test('procedures can be filtered by status', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();

    Procedure::factory()->for($shift)->create([
        'name' => 'Booking Case',
        'status' => ProcedureStatus::Booking,
    ]);
    Procedure::factory()->for($shift)->admitted()->create([
        'name' => 'Admitted Case',
    ]);
    Procedure::factory()->for($shift)->discharged()->create([
        'name' => 'Discharged Case',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('setDays', 0)
        ->assertSee('Booking Case')
        ->assertSee('Admitted Case')
        ->assertSee('Discharged Case')
        ->call('setStatusFilter', ProcedureStatus::Booking->value)
        ->assertSet('statusFilter', ProcedureStatus::Booking->value)
        ->assertSee('Booking Case')
        ->assertDontSee('Admitted Case')
        ->assertDontSee('Discharged Case')
        ->call('setStatusFilter', ProcedureStatus::Admitted->value)
        ->assertSee('Admitted Case')
        ->assertDontSee('Booking Case')
        ->assertDontSee('Discharged Case')
        ->call('setStatusFilter', ProcedureStatus::Discharged->value)
        ->assertSee('Discharged Case')
        ->assertDontSee('Booking Case')
        ->assertDontSee('Admitted Case')
        ->call('setStatusFilter', '')
        ->assertSee('Booking Case')
        ->assertSee('Admitted Case')
        ->assertSee('Discharged Case');
});

test('search finds procedures regardless of status filter', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->create(['name' => 'Status Ignored Patient']);

    Procedure::factory()->for($shift)->for($patient)->discharged()->create([
        'name' => 'Discharged Search Case',
    ]);
    Procedure::factory()->for($shift)->admitted()->create([
        'name' => 'Other Admitted Case',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('setDays', 0)
        ->call('setStatusFilter', ProcedureStatus::Booking->value)
        ->assertDontSee('Discharged Search Case')
        ->assertDontSee('Other Admitted Case')
        ->set('search', 'Status Ignored')
        ->assertSee('Discharged Search Case')
        ->assertDontSee('Other Admitted Case')
        ->assertSee(__('Searching all procedures. Day and status filters are ignored while searching.'));
});

test('procedure payment ledger can be viewed from a card', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create([
        'name' => 'Knee Surgery',
        'full_amount' => 10000,
    ]);
    $payment = ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 4000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSet('viewingProcedureId', $procedure->id)
        ->assertSet('showViewModal', true)
        ->assertSet('showPaymentLedger', false)
        ->assertSee('1. Add Admission')
        ->assertSee('2. Print File')
        ->assertSee('3. Payment Ledger')
        ->call('togglePaymentLedger')
        ->assertSet('showPaymentLedger', true)
        ->assertSee('Payment Ledger')
        ->assertSee('4,000.00')
        ->assertSee('Cash')
        ->assertSee($user->name)
        ->assertSee($shift->opened_at->format('Y-m-d H:i'))
        ->assertSee('Edit')
        ->assertSee('Add Payment')
        ->call('togglePaymentLedger')
        ->assertSet('showPaymentLedger', false);

    $payment->delete();
});

test('a patient can be admitted with cnic and room number', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $room = Room::factory()->create(['number' => 'Room 12']);
    $procedure = Procedure::factory()->for($shift)->create(['room_number' => null]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->call('addAdmission', $procedure->id)
        ->assertSet('showViewModal', false)
        ->assertSet('showAdmissionModal', true)
        ->set('admissionCnic', '35202-1234567-1')
        ->set('admissionRoomId', $room->id)
        ->call('admitPatient')
        ->assertHasNoErrors()
        ->assertSet('showAdmissionModal', false);

    $procedure->refresh();
    expect($procedure->room_number)->toBe('Room 12')
        ->and($procedure->room_id)->toBe($room->id)
        ->and($procedure->admitted_at)->not->toBeNull()
        ->and($procedure->isAdmitted())->toBeTrue()
        ->and($procedure->status->value)->toBe('admitted')
        ->and($procedure->patient->fresh()->cnic)->toBe('35202-1234567-1');
});

test('cnic and room are required to admit a patient', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create(['room_number' => null]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('addAdmission', $procedure->id)
        ->set('admissionCnic', '')
        ->set('admissionRoomId', null)
        ->call('admitPatient')
        ->assertHasErrors(['admissionCnic', 'admissionRoomId']);

    expect($procedure->refresh()->isAdmitted())->toBeFalse();
});

test('an existing admission can be edited without changing the admission time', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $admittedAt = now()->subDay();
    $patient = Patient::factory()->create(['cnic' => '35202-1111111-1']);
    $room = Room::factory()->create(['number' => 'Room 1']);
    $updatedRoom = Room::factory()->create(['number' => 'Room 9']);
    $procedure = Procedure::factory()->for($shift)->for($patient)->create([
        'room_id' => $room->id,
        'room_number' => 'Room 1',
        'admitted_at' => $admittedAt,
        'status' => ProcedureStatus::Admitted,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('addAdmission', $procedure->id)
        ->assertSet('admissionCnic', '35202-1111111-1')
        ->assertSet('admissionRoomId', $room->id)
        ->set('admissionRoomId', $updatedRoom->id)
        ->call('admitPatient')
        ->assertHasNoErrors();

    $procedure->refresh();
    expect($procedure->room_number)->toBe('Room 9')
        ->and($procedure->room_id)->toBe($updatedRoom->id)
        ->and($procedure->admitted_at->format('Y-m-d H:i'))->toBe($admittedAt->format('Y-m-d H:i'));
});

test('admission details are shown in the procedure detail modal', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->create(['cnic' => '35202-7654321-9']);
    $procedure = Procedure::factory()->for($shift)->for($patient)->admitted()->create([
        'room_number' => 'Room 7',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSee('Admitted')
        ->assertSee('Room 7')
        ->assertSee('35202-7654321-9');
});

test('discharged procedures show a discharged badge instead of admitted', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->discharged()->create([
        'name' => 'Discharged Delivery',
        'room_number' => 'Room 3',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->assertSee('Discharged Delivery')
        ->assertSee('Discharged')
        ->call('viewProcedure', $procedure->id)
        ->assertSee('Discharged')
        ->assertSee('Discharged on');
});

test('procedures not admitted show the add admission action', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create(['admitted_at' => null]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSee('1. Add Admission')
        ->assertSee('2. Print File')
        ->assertSee('3. Payment Ledger');
});

test('print file step is disabled when the procedure type has no documents', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedureType = ProcedureType::factory()->create();
    $procedure = Procedure::factory()->for($shift)->for($procedureType)->create();

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSee('2. Print File')
        ->assertSee('No documents linked')
        ->assertDontSee(route('reception.procedures.file', $procedure), false);
});

test('print file step links to the combined pdf when documents exist', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedureType = ProcedureType::factory()->create();
    ProcedureTypeDocument::factory()->for($procedureType)->create([
        'path' => "procedure-types/{$procedureType->id}/documents/consent.pdf",
    ]);
    $procedure = Procedure::factory()->for($shift)->for($procedureType)->create();

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSee('2. Print File')
        ->assertDontSee('No documents linked')
        ->assertSee(route('reception.procedures.file', $procedure), false);
});

test('only active rooms are available for admission', function () {
    $user = User::factory()->create();
    $activeRoom = Room::factory()->create(['number' => 'Room Active']);
    $inactiveRoom = Room::factory()->inactive()->create(['number' => 'Room Inactive']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->assertSet('rooms', function ($rooms) use ($activeRoom, $inactiveRoom) {
            return $rooms->contains('id', $activeRoom->id)
                && ! $rooms->contains('id', $inactiveRoom->id);
        });
});

test('an occupied room cannot be selected for another admission', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $room = Room::factory()->create(['number' => 'Room Taken']);
    Procedure::factory()->for($shift)->admitted()->create([
        'room_id' => $room->id,
        'room_number' => $room->number,
    ]);
    $procedure = Procedure::factory()->for($shift)->create(['room_number' => null]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('addAdmission', $procedure->id)
        ->set('admissionCnic', '35202-1234567-1')
        ->set('admissionRoomId', $room->id)
        ->call('admitPatient')
        ->assertHasErrors(['admissionRoomId']);

    expect($procedure->refresh()->isAdmitted())->toBeFalse();
});

test('inactive doctors are not available in procedures', function () {
    $user = User::factory()->create();
    $activeDoctor = Doctor::factory()->create(['is_active' => true]);
    $inactiveDoctor = Doctor::factory()->create(['is_active' => false]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->assertSet('doctors', function ($doctors) use ($activeDoctor, $inactiveDoctor) {
            return $doctors->contains('id', $activeDoctor->id)
                && ! $doctors->contains('id', $inactiveDoctor->id);
        });
});

test('admin can add a procedure payment to a previous closed shift', function () {
    $admin = User::factory()->admin()->create();
    $receptionist = User::factory()->receptionist()->create();
    $previousShift = Shift::factory()->for($receptionist)->closed()->create([
        'opened_at' => now()->subDays(2),
        'closed_at' => now()->subDay(),
    ]);
    $currentShift = Shift::factory()->for($admin)->open()->create();
    $procedure = Procedure::factory()->for($previousShift)->create(['full_amount' => 5000]);

    Livewire::actingAs($admin)
        ->test('pages::reception.procedures')
        ->call('addPayment', $procedure->id)
        ->set('paymentAmount', '1500')
        ->set('excludeFromCurrentShift', true)
        ->call('selectPreviousShift', $previousShift->id)
        ->call('savePayment')
        ->assertHasNoErrors();

    $payment = ProcedurePayment::first();
    expect($payment)->not->toBeNull()
        ->amount->toBe(1500.0)
        ->shift_id->toBe($previousShift->id)
        ->and($payment->shift_id)->not->toBe($currentShift->id)
        ->and($previousShift->fresh()->totalProcedureSales())->toBe(1500.0)
        ->and($currentShift->fresh()->totalProcedureSales())->toBe(0.0);
});

test('admin must select a previous shift when excluding the current shift', function () {
    $admin = User::factory()->admin()->create();
    Shift::factory()->for($admin)->open()->create();
    Shift::factory()->closed()->create();
    $procedure = Procedure::factory()->create(['full_amount' => 5000]);

    Livewire::actingAs($admin)
        ->test('pages::reception.procedures')
        ->call('addPayment', $procedure->id)
        ->set('paymentAmount', '1500')
        ->set('excludeFromCurrentShift', true)
        ->call('savePayment')
        ->assertHasErrors(['selectedPreviousShiftId']);

    expect(ProcedurePayment::count())->toBe(0);
});

test('non-admin payments always use the current shift even if previous shift fields are set', function () {
    $user = User::factory()->receptionist()->create();
    $previousShift = Shift::factory()->closed()->create();
    $currentShift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($previousShift)->create(['full_amount' => 5000]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('addPayment', $procedure->id)
        ->set('paymentAmount', '1200')
        ->set('excludeFromCurrentShift', true)
        ->set('selectedPreviousShiftId', $previousShift->id)
        ->call('savePayment')
        ->assertHasNoErrors();

    $payment = ProcedurePayment::first();
    expect($payment)->not->toBeNull()
        ->shift_id->toBe($currentShift->id);
});

test('procedure sales are attributed to the payment shift not the procedure shift', function () {
    $user = User::factory()->create();
    $procedureShift = Shift::factory()->for($user)->closed()->create();
    $paymentShift = Shift::factory()->for($user)->closed()->create();
    $procedure = Procedure::factory()->for($procedureShift)->create(['full_amount' => 5000]);

    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 2000,
        'shift_id' => $paymentShift->id,
        'created_by' => $user->id,
    ]);

    expect($paymentShift->fresh()->totalProcedureSales())->toBe(2000.0)
        ->and($procedureShift->fresh()->totalProcedureSales())->toBe(0.0);
});

test('admin can discard a procedure payment from the ledger', function () {
    $admin = User::factory()->admin()->create();
    $shift = Shift::factory()->for($admin)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create(['full_amount' => 5000]);
    $payment = ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 2000,
        'shift_id' => $shift->id,
        'created_by' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->call('togglePaymentLedger')
        ->assertSeeHtml('wire:click="discardPayment('.$payment->id.')"')
        ->call('discardPayment', $payment->id)
        ->assertHasNoErrors()
        ->assertSee(__('Discarded'))
        ->assertDontSeeHtml('wire:click="discardPayment('.$payment->id.')"');

    $payment->refresh();

    expect($payment->isDiscarded())->toBeTrue()
        ->and($payment->discarded_by)->toBe($admin->id)
        ->and($procedure->fresh()->totalPaid())->toBe(0.0)
        ->and($procedure->fresh()->balance())->toBe(5000.0)
        ->and($procedure->fresh()->isPaid())->toBeFalse()
        ->and($shift->fresh()->totalProcedureSales())->toBe(0.0);
});

test('admin cannot discard a procedure payment twice', function () {
    $admin = User::factory()->admin()->create();
    Shift::factory()->for($admin)->open()->create();
    $procedure = Procedure::factory()->create(['full_amount' => 5000]);
    $payment = ProcedurePayment::factory()->for($procedure)->discarded($admin)->create([
        'amount' => 2000,
        'created_by' => $admin->id,
    ]);
    $discardedAt = $payment->discarded_at;

    Livewire::actingAs($admin)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->call('discardPayment', $payment->id)
        ->assertHasNoErrors();

    expect($payment->fresh()->discarded_at?->equalTo($discardedAt))->toBeTrue();
});

test('non-admins cannot discard a procedure payment', function () {
    $user = User::factory()->receptionist()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create(['full_amount' => 5000]);
    $payment = ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 2000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->call('togglePaymentLedger')
        ->assertDontSeeHtml('wire:click="discardPayment('.$payment->id.')"')
        ->call('discardPayment', $payment->id)
        ->assertForbidden();

    expect($payment->fresh()->isDiscarded())->toBeFalse()
        ->and($procedure->fresh()->totalPaid())->toBe(2000.0);
});
