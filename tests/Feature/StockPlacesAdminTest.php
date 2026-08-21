<?php

use App\Models\Place;
use App\Models\Thing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can visit stock places page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.stock-places'))
        ->assertSuccessful();
});

test('non admin cannot visit stock places page', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('admin.stock-places'))
        ->assertForbidden();
});

test('admin can create a place', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.stock-places')
        ->call('openCreatePlaceModal')
        ->set('placeName', 'ER Cupboard')
        ->set('placeIsActive', true)
        ->call('savePlace')
        ->assertHasNoErrors();

    expect(Place::query()->where('name', 'ER Cupboard')->exists())->toBeTrue();
});

test('admin can create a thing', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.stock-places')
        ->set('activeTab', 'things')
        ->call('openCreateThingModal')
        ->set('thingName', 'Gloves')
        ->set('thingUnit', 'box')
        ->set('thingIsActive', true)
        ->call('saveThing')
        ->assertHasNoErrors();

    $thing = Thing::query()->where('name', 'Gloves')->first();

    expect($thing)->not->toBeNull()
        ->and($thing->unit)->toBe('box');
});

test('admin can assign a thing to a place with stock point', function () {
    $admin = User::factory()->admin()->create();
    $place = Place::factory()->create(['name' => 'Ward Store']);
    $thing = Thing::factory()->create(['name' => 'Syringes']);

    Livewire::actingAs($admin)
        ->test('pages::admin.stock-places')
        ->set('activeTab', 'assign')
        ->set('assignPlaceId', $place->id)
        ->call('openAssignModal')
        ->set('assignThingId', $thing->id)
        ->set('assignStockPoint', 20)
        ->call('assignThing')
        ->assertHasNoErrors();

    expect($place->fresh()->things()->where('things.id', $thing->id)->first()?->pivot->stock_point)
        ->toBe(20);
});

test('admin can update assignment stock point', function () {
    $admin = User::factory()->admin()->create();
    $place = Place::factory()->create();
    $thing = Thing::factory()->create();
    $place->things()->attach($thing->id, ['stock_point' => 10, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test('pages::admin.stock-places')
        ->set('activeTab', 'assign')
        ->set('assignPlaceId', $place->id)
        ->call('editAssignment', $thing->id)
        ->set('editStockPoint', 35)
        ->set('editAssignmentIsActive', true)
        ->call('saveAssignment')
        ->assertHasNoErrors();

    expect($place->fresh()->things()->where('things.id', $thing->id)->first()?->pivot->stock_point)
        ->toBe(35);
});
