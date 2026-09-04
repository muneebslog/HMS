<?php

use App\Enums\ProcedureStatus;
use App\Models\Doctor;
use App\Models\Procedure;
use App\Models\ProcedureType;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('creating a procedure sets status to booking', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $doctor = Doctor::factory()->create();
    $procedureType = ProcedureType::factory()->create(['name' => 'Normal Delivery']);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->set('patientName', 'Jane Doe')
        ->set('patientPhone', '03001234567')
        ->set('husbandName', 'John Doe')
        ->set('patientAge', 28)
        ->set('procedureTypeId', $procedureType->id)
        ->set('expectedDeliveryDate', '2026-12-15')
        ->set('fullAmount', '5000')
        ->set('doctorId', $doctor->id)
        ->call('saveProcedure')
        ->assertHasNoErrors();

    $procedure = Procedure::query()->latest('id')->first();

    expect($procedure)->not->toBeNull()
        ->and($procedure->status)->toBe(ProcedureStatus::Booking);
});

test('admitting a procedure sets status to admitted', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $room = Room::factory()->create(['number' => 'Room 12']);
    $procedure = Procedure::factory()->for($shift)->create();

    expect($procedure->status)->toBe(ProcedureStatus::Booking);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('addAdmission', $procedure->id)
        ->set('admissionCnic', '35202-1234567-1')
        ->set('admissionRoomId', $room->id)
        ->call('admitPatient')
        ->assertHasNoErrors();

    expect($procedure->fresh()->status)->toBe(ProcedureStatus::Admitted);
});

test('discharging a procedure sets status to discharged', function () {
    $user = User::factory()->indoor()->create();
    $procedure = Procedure::factory()->admitted()->create();

    expect($procedure->status)->toBe(ProcedureStatus::Admitted);

    Livewire::actingAs($user)
        ->test('pages::indoor.procedure', ['procedure' => $procedure])
        ->call('setActiveTab', 'discharge')
        ->call('dischargePatient')
        ->assertHasNoErrors();

    expect($procedure->fresh()->status)->toBe(ProcedureStatus::Discharged);
});

test('booking procedures show a booking badge on the reception list', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    Procedure::factory()->for($shift)->create([
        'name' => 'Booking Case',
        'status' => ProcedureStatus::Booking,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->assertSee('Booking Case')
        ->assertSee(__('Booking'));
});
