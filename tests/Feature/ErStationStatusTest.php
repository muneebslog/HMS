<?php

use App\Models\Doctor;
use App\Models\Station;
use App\Models\User;
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

test('shows the er pc as online when it checked in recently', function () {
    Station::factory()->online()->create(['name' => 'ER Bay PC', 'allowed_pages' => ['display.er']]);

    Livewire::test('er-station-status')
        ->assertSee(__('ER PC online'))
        ->assertSee('ER Bay PC')
        ->assertDontSee(__('ER PC offline'));
});

test('shows the er pc as offline once it stops checking in', function () {
    Station::factory()->registered()->create([
        'name' => 'ER Bay PC',
        'allowed_pages' => ['display.er_drips'],
        'last_seen_at' => now()->subSeconds(Station::ONLINE_THRESHOLD_SECONDS + 1),
    ]);

    Livewire::test('er-station-status')
        ->assertSee(__('ER PC offline'))
        ->assertSee(__('ER cannot see new medication until this PC is back online. Inform ER staff.'))
        ->assertDontSee(__('ER PC online'));
});

test('flips to offline after the threshold passes without a heartbeat', function () {
    Station::factory()->online()->create(['allowed_pages' => ['display.er']]);

    $component = Livewire::test('er-station-status')->assertSee(__('ER PC online'));

    Carbon::setTestNow(now()->addSeconds(Station::ONLINE_THRESHOLD_SECONDS + 1));

    $component->call('$refresh')->assertSee(__('ER PC offline'));
});

test('a station pc polling the er page keeps the doctor status online', function () {
    Carbon::setTestNow(now());
    registerStationDevice(['display.er']);
    $this->get(route('display.er'))->assertSuccessful();

    Carbon::setTestNow(now()->addSeconds(Station::ONLINE_THRESHOLD_SECONDS - 10));
    $this->get(route('display.er'))->assertSuccessful();

    Carbon::setTestNow(now()->addSeconds(Station::ONLINE_THRESHOLD_SECONDS - 10));

    Livewire::test('er-station-status')->assertSee(__('ER PC online'));
});

test('ignores stations that do not show the er page, are disabled, or are unregistered', function () {
    Station::factory()->online()->create(['allowed_pages' => ['display.drips']]);
    Station::factory()->online()->inactive()->create(['allowed_pages' => ['display.er']]);
    Station::factory()->create(['allowed_pages' => ['display.er'], 'last_seen_at' => now()]);

    Livewire::test('er-station-status')
        ->assertSee(__('No ER PC registered'))
        ->assertDontSee(__('ER PC online'));
});

test('a registered er pc that never checked in shows offline', function () {
    Station::factory()->registered()->create(['allowed_pages' => ['display.er']]);

    Livewire::test('er-station-status')
        ->assertSee(__('ER PC offline'))
        ->assertSee(__('Never seen'));
});

test('shows online when any one of several er pcs is online', function () {
    Station::factory()->registered()->create(['allowed_pages' => ['display.er']]);
    Station::factory()->online()->create(['allowed_pages' => ['display.er_drips']]);

    Livewire::test('er-station-status')->assertSee(__('ER PC online'));
});

test('doctors with the medication page see the er status in the sidebar', function () {
    $user = User::factory()->doctor()->create();
    Doctor::factory()->withMedicationPage()->forUser($user)->create();
    Station::factory()->online()->create(['allowed_pages' => ['display.er']]);

    $this->actingAs($user)
        ->get(route('doctor.medication'))
        ->assertSuccessful()
        ->assertSee('data-test="er-station-status"', false)
        ->assertSee(__('ER PC online'));
});

test('doctors without the medication page do not see the er status', function () {
    $user = User::factory()->doctor()->create();
    Doctor::factory()->forUser($user)->create();

    $this->actingAs($user)
        ->get(route('doctor.portal'))
        ->assertSuccessful()
        ->assertDontSee('data-test="er-station-status"', false);
});
