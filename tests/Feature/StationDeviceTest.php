<?php

use App\Models\Station;
use App\Models\User;
use App\Services\StationDeviceService;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('guests on an unregistered pc cannot open station pages', function (string $routeName) {
    $this->get(route($routeName))
        ->assertForbidden()
        ->assertSee(__('This PC is not registered as a station'));
})->with(['display.er', 'display.medication', 'display.drips', 'display.er_drips']);

test('a registered station pc opens its pages without signing in', function () {
    registerStationDevice(['display.er_drips']);

    $this->get(route('display.er_drips'))->assertSuccessful();
    $this->get(route('display.er'))->assertSuccessful();
    $this->get(route('display.drips'))->assertSuccessful();

    $this->assertGuest();
});

test('a station pc cannot open pages it is not allowed', function () {
    $station = registerStationDevice(['display.drips']);

    $this->get(route('display.drips'))->assertSuccessful();

    $this->get(route('display.er'))
        ->assertForbidden()
        ->assertSee(__('This page is not enabled for this station'))
        ->assertSee($station->name);
});

test('a disabled station loses access', function () {
    $station = registerStationDevice(['display.er']);
    $station->update(['is_active' => false]);

    $this->get(route('display.er'))->assertForbidden();
});

test('an unregistered station token no longer works', function () {
    $station = registerStationDevice(['display.er']);
    app(StationDeviceService::class)->unregister($station);

    $this->get(route('display.er'))->assertForbidden();
});

test('signed in staff with page access can still open station pages', function () {
    $user = User::factory()->indoor()->create();

    $this->actingAs($user)->get(route('display.er'))->assertSuccessful();
});

test('station pc records a heartbeat with page, ip and user agent', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $station = registerStationDevice(['display.er_drips']);

    expect($station->isOnline())->toBeFalse();

    $this->withHeader('User-Agent', 'StationBrowser/1.0')
        ->get(route('display.er'))
        ->assertSuccessful();

    $station->refresh();

    expect($station->last_seen_at->equalTo(now()))->toBeTrue()
        ->and($station->last_page)->toBe('display.er')
        ->and($station->last_ip)->toBe('127.0.0.1')
        ->and($station->last_user_agent)->toBe('StationBrowser/1.0')
        ->and($station->isOnline())->toBeTrue();
});

test('heartbeats on the same page are throttled', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $station = registerStationDevice(['display.er']);
    $this->get(route('display.er'));

    Carbon::setTestNow('2026-10-06 10:00:10');
    $this->get(route('display.er'));
    expect($station->refresh()->last_seen_at->toDateTimeString())->toBe('2026-10-06 10:00:00');

    Carbon::setTestNow('2026-10-06 10:00:31');
    $this->get(route('display.er'));
    expect($station->refresh()->last_seen_at->toDateTimeString())->toBe('2026-10-06 10:00:31');
});

test('station goes offline after the threshold without a heartbeat', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $station = Station::factory()->online()->create();

    expect($station->isOnline())->toBeTrue()
        ->and(Station::query()->online()->count())->toBe(1);

    Carbon::setTestNow(now()->addSeconds(Station::ONLINE_THRESHOLD_SECONDS + 1));

    expect($station->isOnline())->toBeFalse()
        ->and(Station::query()->online()->count())->toBe(0);
});

test('station home redirects a registered pc to its station page', function () {
    registerStationDevice(['display.drips']);

    $this->get(route('station.home'))->assertRedirect(route('display.drips'));
});

test('station home shows the unregistered screen on an unknown pc', function () {
    $this->get(route('station.home'))
        ->assertForbidden()
        ->assertSee(__('This PC is not registered as a station'));
});

test('only admins can open the stations admin page', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.stations'))
        ->assertSuccessful()
        ->assertSee(__('Stations'));

    $this->actingAs(User::factory()->indoor()->create())
        ->get(route('admin.stations'))
        ->assertForbidden();
});

test('admin can create and edit a station', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.stations')
        ->call('openCreateModal')
        ->set('name', 'ER PC 1')
        ->set('location', 'ER Bay')
        ->set('allowedPages', ['display.er'])
        ->call('saveStation')
        ->assertHasNoErrors();

    $station = Station::query()->sole();

    expect($station->name)->toBe('ER PC 1')
        ->and($station->location)->toBe('ER Bay')
        ->and($station->allowed_pages)->toBe(['display.er'])
        ->and($station->isRegistered())->toBeFalse();

    Livewire::actingAs($admin)
        ->test('pages::admin.stations')
        ->call('editStation', $station->id)
        ->set('allowedPages', ['display.er', 'display.drips'])
        ->call('saveStation')
        ->assertHasNoErrors();

    expect($station->refresh()->allowed_pages)->toBe(['display.er', 'display.drips']);
});

test('a station needs at least one valid page', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.stations')
        ->set('name', 'ER PC 1')
        ->set('allowedPages', [])
        ->call('saveStation')
        ->assertHasErrors(['allowedPages'])
        ->set('allowedPages', ['admin.users'])
        ->call('saveStation')
        ->assertHasErrors(['allowedPages.0']);

    expect(Station::query()->count())->toBe(0);
});

test('admin registering this pc issues a device token and cookie', function () {
    $station = Station::factory()->create(['allowed_pages' => ['display.er']]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.stations')
        ->call('registerThisDevice', $station->id);

    $station->refresh();

    expect($station->isRegistered())->toBeTrue()
        ->and($station->registered_at)->not->toBeNull();

    $queued = collect(cookie()->getQueuedCookies())->firstWhere(fn ($cookie) => $cookie->getName() === StationDeviceService::COOKIE);

    expect($queued)->not->toBeNull()
        ->and(hash('sha256', $queued->getValue()))->toBe($station->device_token_hash);
});

test('re-registering a station locks out the previous pc', function () {
    $station = registerStationDevice(['display.er']);

    app(StationDeviceService::class)->issueToken($station);

    $this->get(route('display.er'))->assertForbidden();
});

test('admin can unregister and disable stations', function () {
    $station = Station::factory()->online()->create();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.stations')
        ->assertSee($station->name)
        ->assertSee(__('Online'))
        ->call('unregister', $station->id)
        ->call('toggleActive', $station->id);

    $station->refresh();

    expect($station->isRegistered())->toBeFalse()
        ->and($station->last_seen_at)->toBeNull()
        ->and($station->is_active)->toBeFalse();
});

test('stations admin page shows online and offline status', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    Station::factory()->online()->create(['name' => 'ER PC 1']);
    Station::factory()->registered()->create(['name' => 'Drip PC', 'last_seen_at' => now()->subHour()]);
    Station::factory()->create(['name' => 'Spare PC']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.stations')
        ->assertSee('1 of 3 stations online')
        ->assertSeeInOrder([__('Online')])
        ->assertSee(__('Offline'))
        ->assertSee(__('No PC registered'));
});
