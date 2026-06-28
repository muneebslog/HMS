<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedurePayment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('procedureName', 'Appendectomy')
        ->set('fullAmount', '5000')
        ->set('roomNumber', 'Room 101')
        ->set('doctorId', $doctor->id)
        ->set('advancePayment', '2000')
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $patient = Patient::where('name', 'John Doe')->first();
    expect($patient)->not->toBeNull()
        ->phone->toBe('1234567890')
        ->age->toBe(30)
        ->gender->toBe('male');

    $procedure = Procedure::where('patient_id', $patient->id)->first();
    expect($procedure)->not->toBeNull()
        ->name->toBe('Appendectomy')
        ->full_amount->toBe(5000.0)
        ->room_number->toBe('Room 101')
        ->doctor_id->toBe($doctor->id);

    expect($procedure->payments)->toHaveCount(1)
        ->and($procedure->payments->first())
        ->amount->toBe(2000.0);

    expect($procedure->totalPaid())->toBe(2000.0)
        ->and($procedure->balance())->toBe(3000.0)
        ->and($procedure->isPaid())->toBeFalse();
});

test('a procedure can be created without a doctor', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'Jane Doe')
        ->set('patientPhone', '0987654321')
        ->set('patientGender', 'female')
        ->set('patientAge', 25)
        ->set('procedureName', 'General Checkup')
        ->set('fullAmount', '1000')
        ->set('roomNumber', 'Room 202')
        ->set('doctorId', '')
        ->set('advancePayment', '0')
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $procedure = Procedure::first();
    expect($procedure)->doctor_id->toBeNull()
        ->and($procedure->payments)->toHaveCount(0)
        ->and($procedure->balance())->toBe(1000.0);
});

test('an open shift is required to create a procedure', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('procedureName', 'Appendectomy')
        ->set('fullAmount', '5000')
        ->set('roomNumber', 'Room 101')
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
        ->and($procedure->isPaid())->toBeFalse();
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
    $patient = Patient::factory()->create([
        'name' => 'John Doe',
        'phone' => '1234567890',
        'age' => 30,
        'gender' => 'male',
    ]);
    $procedure = Procedure::factory()->for($shift)->for($patient)->create([
        'full_amount' => 5000,
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

test('full amount cannot be reduced below total paid', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->create([
        'name' => 'Jane Doe',
        'phone' => '0987654321',
        'age' => 25,
        'gender' => 'female',
    ]);
    $procedure = Procedure::factory()->for($shift)->for($patient)->create([
        'full_amount' => 5000,
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

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('procedureName', 'Appendectomy')
        ->set('fullAmount', '5000')
        ->set('roomNumber', 'Room 101')
        ->set('advancePayment', '6000')
        ->call('saveProcedure')
        ->assertHasErrors(['advancePayment']);

    expect(Procedure::count())->toBe(0);
});

test('procedures are listed with correct totals', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create([
        'name' => 'Knee Surgery',
        'full_amount' => 10000,
    ]);
    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 4000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->assertSee('Knee Surgery')
        ->assertSee($procedure->patient->name)
        ->assertSee('10,000.00')
        ->assertSee('4,000.00')
        ->assertSee('6,000.00');
});

test('procedure payment ledger can be viewed from the list', function () {
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
        ->assertSee('Payment Ledger')
        ->assertSee('4,000.00')
        ->assertSee($user->name)
        ->assertSee($shift->opened_at->format('Y-m-d H:i'));

    $payment->delete();
});
