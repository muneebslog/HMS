<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedurePayment;
use App\Models\ProcedureType;
use App\Models\ProcedureTypeDocument;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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

test('the full amount can be edited when a discount is given', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->withPhone('03001234567')->create([
        'name' => 'John Doe',
        'husband_name' => 'James Doe',
        'age' => 30,
        'gender' => 'female',
    ]);
    $procedure = Procedure::factory()->for($shift)->for($patient)->create([
        'full_amount' => 5000,
        'expected_delivery_date' => '2026-12-15',
    ]);
    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 3000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('edit', $procedure->id)
        ->set('fullAmount', '3500')
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $procedure->refresh();
    expect($procedure->full_amount)->toBe(3500.0)
        ->and($procedure->balance())->toBe(500.0)
        ->and($procedure->isPaid())->toBeFalse();
});

test('procedure maternity fields can be updated', function () {
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
        ->set('husbandName', 'Robert Doe')
        ->set('expectedDeliveryDate', '2026-11-20')
        ->set('procedureTypeId', $updatedType->id)
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $procedure->refresh();
    expect($procedure->name)->toBe('C-Section Package')
        ->and($procedure->procedure_type_id)->toBe($updatedType->id)
        ->and($procedure->expected_delivery_date->format('Y-m-d'))->toBe('2026-11-20')
        ->and($procedure->patient->fresh()->husband_name)->toBe('Robert Doe');
});

test('full amount cannot be reduced below total paid', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->withPhone('03009876543')->create([
        'name' => 'Jane Doe',
        'husband_name' => 'John Doe',
        'age' => 25,
        'gender' => 'female',
    ]);
    $procedure = Procedure::factory()->for($shift)->for($patient)->create([
        'full_amount' => 5000,
        'expected_delivery_date' => '2026-12-01',
    ]);
    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 4000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('edit', $procedure->id)
        ->set('fullAmount', '3000')
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $procedure->refresh();
    expect($procedure->full_amount)->toBe(5000.0);
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
    $procedure = Procedure::factory()->for($shift)->for($patient)->create([
        'room_number' => 'Room 7',
        'admitted_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSee('Admitted')
        ->assertSee('Room 7')
        ->assertSee('35202-7654321-9');
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
