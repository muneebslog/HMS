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

test('admin can bulk add things assigned to one place', function () {
    $admin = User::factory()->admin()->create();
    $place = Place::factory()->create(['name' => 'Ward Store']);

    $rows = array_fill(0, 10, ['name' => '', 'unit' => '', 'stock_point' => '']);
    $rows[0] = ['name' => 'Gloves', 'unit' => 'box', 'stock_point' => '20'];
    $rows[1] = ['name' => 'Syringes', 'unit' => 'pack', 'stock_point' => '50'];
    $rows[2] = ['name' => 'Masks', 'unit' => '', 'stock_point' => '15'];

    Livewire::actingAs($admin)
        ->test('pages::admin.stock-places')
        ->set('activeTab', 'things')
        ->call('openBulkModal')
        ->set('bulkPlaceId', $place->id)
        ->set('bulkRows', $rows)
        ->call('saveBulkThings')
        ->assertHasNoErrors();

    expect(Thing::query()->count())->toBe(3)
        ->and($place->fresh()->things)->toHaveCount(3)
        ->and($place->things()->where('name', 'Gloves')->first()?->pivot->stock_point)->toBe(20)
        ->and($place->things()->where('name', 'Syringes')->first()?->pivot->stock_point)->toBe(50)
        ->and($place->things()->where('name', 'Masks')->first()?->pivot->stock_point)->toBe(15);
});

test('bulk add requires a place and at least one named row', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.stock-places')
        ->call('openBulkModal')
        ->set('bulkPlaceId', null)
        ->call('saveBulkThings')
        ->assertHasErrors(['bulkPlaceId']);

    $place = Place::factory()->create();
    $rows = array_fill(0, 10, ['name' => '', 'unit' => '', 'stock_point' => '']);

    Livewire::actingAs($admin)
        ->test('pages::admin.stock-places')
        ->call('openBulkModal')
        ->set('bulkPlaceId', $place->id)
        ->set('bulkRows', $rows)
        ->call('saveBulkThings')
        ->assertHasErrors(['bulkRows']);
});

test('admin can assign an existing thing to a place', function () {
    $admin = User::factory()->admin()->create();
    $place = Place::factory()->create(['name' => 'ER Shelf']);
    $thing = Thing::factory()->create(['name' => 'Gauze']);

    Livewire::actingAs($admin)
        ->test('pages::admin.stock-places')
        ->set('activeTab', 'things')
        ->call('openAssignModal', $thing->id)
        ->set('assignPlaceId', $place->id)
        ->set('assignStockPoint', 12)
        ->call('assignThingToPlace')
        ->assertHasNoErrors();

    expect($place->fresh()->things()->where('things.id', $thing->id)->first()?->pivot->stock_point)
        ->toBe(12);
});

test('admin can update stock point when reassigning', function () {
    $admin = User::factory()->admin()->create();
    $place = Place::factory()->create();
    $thing = Thing::factory()->create();
    $place->things()->attach($thing->id, ['stock_point' => 10, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test('pages::admin.stock-places')
        ->call('openAssignModal', $thing->id)
        ->set('assignPlaceId', $place->id)
        ->set('assignStockPoint', 35)
        ->call('assignThingToPlace')
        ->assertHasNoErrors();

    expect($place->fresh()->things()->where('things.id', $thing->id)->first()?->pivot->stock_point)
        ->toBe(35);
});

test('admin can remove a thing assignment from the things tab', function () {
    $admin = User::factory()->admin()->create();
    $place = Place::factory()->create();
    $thing = Thing::factory()->create();
    $place->things()->attach($thing->id, ['stock_point' => 5, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test('pages::admin.stock-places')
        ->set('activeTab', 'things')
        ->call('removeAssignment', $thing->id, $place->id)
        ->assertHasNoErrors();

    expect($place->fresh()->things()->where('things.id', $thing->id)->exists())->toBeFalse();
});
