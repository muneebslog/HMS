<?php

use App\Enums\PaymentMode;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedurePayment;
use App\Models\ProcedureType;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot view the procedure bill print page', function () {
    $procedure = Procedure::factory()->create();

    $this->get(route('reception.procedures.print', $procedure))
        ->assertRedirect(route('login'));
});

test('receptionists without an open shift cannot view the procedure bill print page', function () {
    $user = User::factory()->receptionist()->create();
    $procedure = Procedure::factory()->create();

    $this->actingAs($user)
        ->get(route('reception.procedures.print', $procedure))
        ->assertRedirect();
});

test('a receptionist with an open shift can view the procedure bill with latest payment data', function () {
    $user = User::factory()->receptionist()->create(['name' => 'Front Desk']);
    Shift::factory()->for($user)->open()->create();

    $patient = Patient::factory()->create([
        'name' => 'Ayesha Khan',
        'husband_name' => 'Ali Khan',
        'age' => 28,
    ]);
    $doctor = Doctor::factory()->create(['name' => 'Dr. Sara']);
    $procedureType = ProcedureType::factory()->create(['name' => 'Normal Delivery']);

    $procedure = Procedure::factory()
        ->for($patient)
        ->for($procedureType)
        ->for($doctor)
        ->create([
            'name' => 'Normal Delivery',
            'full_amount' => 50000,
            'created_by' => $user->id,
        ]);

    ProcedurePayment::factory()->create([
        'procedure_id' => $procedure->id,
        'amount' => 10000,
        'mode' => PaymentMode::Cash,
        'created_by' => $user->id,
    ]);

    ProcedurePayment::factory()->create([
        'procedure_id' => $procedure->id,
        'amount' => 5000,
        'mode' => PaymentMode::Online,
        'created_by' => $user->id,
    ]);

    $response = $this->actingAs($user)
        ->get(route('reception.procedures.print', $procedure));

    $response->assertOk()
        ->assertSee(__('Procedure Bill'))
        ->assertSee('Ayesha Khan')
        ->assertSee('Ali Khan')
        ->assertSee('Normal Delivery')
        ->assertSee('Dr. Sara')
        ->assertSee('10,000.00')
        ->assertSee('5,000.00')
        ->assertSee('50,000.00')
        ->assertSee('15,000.00')
        ->assertSee('35,000.00')
        ->assertSee(__('Cash'))
        ->assertSee(__('Online'))
        ->assertSee('Front Desk')
        ->assertSee('window.print()', false);
});

test('the procedure bill print page reflects newly added payments', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    $procedure = Procedure::factory()->create([
        'full_amount' => 20000,
        'created_by' => $user->id,
    ]);

    ProcedurePayment::factory()->create([
        'procedure_id' => $procedure->id,
        'amount' => 4000,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('reception.procedures.print', $procedure))
        ->assertOk()
        ->assertSee('4,000.00')
        ->assertSee('16,000.00');

    ProcedurePayment::factory()->create([
        'procedure_id' => $procedure->id,
        'amount' => 6000,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('reception.procedures.print', $procedure))
        ->assertOk()
        ->assertSee('6,000.00')
        ->assertSee('10,000.00')
        ->assertSee('10,000.00');
});

test('the procedure view modal includes a print button next to the balance', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    $procedure = Procedure::factory()->create([
        'full_amount' => 10000,
        'created_by' => $user->id,
    ]);

    Livewire\Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSee(route('reception.procedures.print', $procedure), false)
        ->assertSee(__('Print'));
});
