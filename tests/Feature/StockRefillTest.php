<?php

use App\Models\HealthAide;
use App\Models\Place;
use App\Models\StockCheck;
use App\Models\Thing;
use App\Models\User;
use App\Services\HealthAidePinSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('assigned role can visit stock refill page', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('stock.refill'))
        ->assertSuccessful();
});

test('pending role user is redirected away from stock refill', function () {
    $user = User::factory()->user()->create();

    $this->actingAs($user)
        ->get(route('stock.refill'))
        ->assertRedirect(route('pending-role'));
});

test('guest cannot visit stock refill page', function () {
    $this->get(route('stock.refill'))
        ->assertRedirect(route('login'));
});

test('staff cannot save check without health aide pin', function () {
    $user = User::factory()->indoor()->create();
    $place = Place::factory()->create();
    $thing = Thing::factory()->create();
    $place->things()->attach($thing->id, ['stock_point' => 10, 'is_active' => true]);

    Livewire::actingAs($user)
        ->test('pages::stock.refill')
        ->set('placeId', $place->id)
        ->set('counts', [(string) $thing->id => 3])
        ->call('saveCheck')
        ->assertSet('showPinModal', true);

    expect(StockCheck::query()->count())->toBe(0);
});

test('invalid pin is rejected', function () {
    $user = User::factory()->indoor()->create();
    HealthAide::factory()->create(['pin' => '1234']);

    Livewire::actingAs($user)
        ->test('pages::stock.refill')
        ->set('pin', '9999')
        ->call('verifyPin')
        ->assertHasErrors('pin')
        ->assertSet('showPinModal', true);
});

test('staff can unlock with pin and save refill check with correct refill needed', function () {
    $user = User::factory()->indoor()->create();
    $aide = HealthAide::factory()->create(['pin' => '1234', 'name' => 'Aide One']);
    $place = Place::factory()->create(['name' => 'ER Store']);
    $gloves = Thing::factory()->create(['name' => 'Gloves']);
    $masks = Thing::factory()->create(['name' => 'Masks']);

    $place->things()->attach($gloves->id, ['stock_point' => 20, 'is_active' => true]);
    $place->things()->attach($masks->id, ['stock_point' => 15, 'is_active' => true]);

    Livewire::actingAs($user)
        ->test('pages::stock.refill')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->assertHasNoErrors()
        ->assertSet('showPinModal', false)
        ->set('placeId', $place->id)
        ->set('counts', [
            (string) $gloves->id => 12,
            (string) $masks->id => 15,
        ])
        ->call('saveCheck')
        ->assertHasNoErrors();

    $check = StockCheck::query()->with('items')->first();

    expect($check)->not->toBeNull()
        ->and($check->place_id)->toBe($place->id)
        ->and($check->health_aide_id)->toBe($aide->id)
        ->and($check->user_id)->toBe($user->id)
        ->and($check->items)->toHaveCount(2);

    $gloveItem = $check->items->firstWhere('thing_id', $gloves->id);
    $maskItem = $check->items->firstWhere('thing_id', $masks->id);

    expect($gloveItem->stock_point)->toBe(20)
        ->and($gloveItem->counted_quantity)->toBe(12)
        ->and($gloveItem->refill_needed)->toBe(8)
        ->and($maskItem->refill_needed)->toBe(0);
});

test('inactive place thing and assignment are excluded from staff list', function () {
    $user = User::factory()->receptionist()->create();
    HealthAide::factory()->create(['pin' => '1234']);

    $activePlace = Place::factory()->create(['name' => 'Active Place']);
    $inactivePlace = Place::factory()->inactive()->create(['name' => 'Inactive Place']);

    $activeThing = Thing::factory()->create(['name' => 'Active Thing']);
    $inactiveThing = Thing::factory()->inactive()->create(['name' => 'Inactive Thing']);
    $detachedThing = Thing::factory()->create(['name' => 'Detached Thing']);

    $activePlace->things()->attach($activeThing->id, ['stock_point' => 5, 'is_active' => true]);
    $activePlace->things()->attach($inactiveThing->id, ['stock_point' => 5, 'is_active' => true]);
    $activePlace->things()->attach($detachedThing->id, ['stock_point' => 5, 'is_active' => false]);

    $component = Livewire::actingAs($user)
        ->test('pages::stock.refill')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->set('placeId', $activePlace->id);

    $placeNames = $component->instance()->places->pluck('name')->all();
    $thingNames = $component->instance()->placeThings->pluck('name')->all();

    expect($placeNames)->toContain('Active Place')
        ->and($placeNames)->not->toContain('Inactive Place')
        ->and($thingNames)->toContain('Active Thing')
        ->and($thingNames)->not->toContain('Inactive Thing')
        ->and($thingNames)->not->toContain('Detached Thing');
});

test('saved check appears in admin history', function () {
    $admin = User::factory()->admin()->create();
    $aide = HealthAide::factory()->create();
    $place = Place::factory()->create(['name' => 'Pharmacy Shelf']);
    $thing = Thing::factory()->create(['name' => 'Gauze']);
    $place->things()->attach($thing->id, ['stock_point' => 10, 'is_active' => true]);

    $check = StockCheck::factory()->create([
        'place_id' => $place->id,
        'health_aide_id' => $aide->id,
        'user_id' => $admin->id,
    ]);

    $check->items()->create([
        'thing_id' => $thing->id,
        'stock_point' => 10,
        'counted_quantity' => 4,
        'refill_needed' => 6,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.stock-places')
        ->set('activeTab', 'history')
        ->assertSee('Pharmacy Shelf')
        ->call('viewCheck', $check->id)
        ->assertSee('Gauze')
        ->assertSee('6');
});

test('pin session can be locked', function () {
    $user = User::factory()->indoor()->create();
    HealthAide::factory()->create(['pin' => '4321']);

    Livewire::actingAs($user)
        ->test('pages::stock.refill')
        ->set('pin', '4321')
        ->call('verifyPin')
        ->assertSet('showPinModal', false)
        ->call('lock')
        ->assertSet('showPinModal', true);

    expect(app(HealthAidePinSession::class)->check())->toBeFalse();
});
