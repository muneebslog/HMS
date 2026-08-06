<?php

use App\Models\Procedure;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $this->get(route('reception.rooms'))
        ->assertRedirect(route('login'));
});

test('authenticated receptionists can visit the rooms page', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    $this->actingAs($user)
        ->get(route('reception.rooms'))
        ->assertOk()
        ->assertSeeLivewire('pages::reception.rooms');
});

test('the rooms page shows free and occupied rooms', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    $freeRoom = Room::factory()->create(['number' => 'Room Free']);
    $occupiedRoom = Room::factory()->create(['number' => 'Room Busy']);
    $inactiveRoom = Room::factory()->inactive()->create(['number' => 'Room Hidden']);

    $procedure = Procedure::factory()->admitted()->create([
        'room_id' => $occupiedRoom->id,
        'room_number' => $occupiedRoom->number,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.rooms')
        ->assertSee('Room Free')
        ->assertSee('Room Busy')
        ->assertDontSee('Room Hidden')
        ->assertSee(__('Free'))
        ->assertSee(__('Occupied'))
        ->assertSee($procedure->patient->name)
        ->assertSet('freeCount', 1)
        ->assertSet('occupiedCount', 1);
});

test('admitting a patient marks the room occupied on the rooms page', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $room = Room::factory()->create(['number' => 'Room 5']);
    $procedure = Procedure::factory()->for($shift)->create([
        'room_number' => null,
    ]);

    expect($room->isOccupied())->toBeFalse();

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('addAdmission', $procedure->id)
        ->set('admissionCnic', '35202-1234567-1')
        ->set('admissionRoomId', $room->id)
        ->call('admitPatient')
        ->assertHasNoErrors();

    expect($room->fresh()->isOccupied())->toBeTrue();

    Livewire::actingAs($user)
        ->test('pages::reception.rooms')
        ->assertSee('Room 5')
        ->assertSee(__('Occupied'))
        ->assertSee($procedure->patient->fresh()->name)
        ->assertSet('occupiedCount', 1)
        ->assertSet('freeCount', 0);
});

test('discharging a patient marks the room free on the rooms page', function () {
    $user = User::factory()->indoor()->create();
    $receptionist = User::factory()->create();
    Shift::factory()->for($receptionist)->open()->create();

    $room = Room::factory()->create(['number' => 'Room 8']);
    $procedure = Procedure::factory()->admitted()->create([
        'room_id' => $room->id,
        'room_number' => $room->number,
    ]);

    expect($room->isOccupied())->toBeTrue();

    Livewire::actingAs($user)
        ->test('pages::indoor.procedure', ['procedure' => $procedure])
        ->call('setActiveTab', 'discharge')
        ->call('dischargePatient')
        ->assertHasNoErrors();

    expect($procedure->fresh()->isDischarged())->toBeTrue()
        ->and($room->fresh()->isOccupied())->toBeFalse()
        ->and($room->fresh()->isFree())->toBeTrue();

    Livewire::actingAs($receptionist)
        ->test('pages::reception.rooms')
        ->assertSee('Room 8')
        ->assertSee(__('Free'))
        ->assertDontSee($procedure->patient->name)
        ->assertSet('occupiedCount', 0)
        ->assertSet('freeCount', 1);
});
