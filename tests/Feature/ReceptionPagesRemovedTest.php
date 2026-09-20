<?php

use App\Enums\UserRole;
use App\Models\Room;
use App\Models\UltrasoundReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('lab tracking ultrasound and rooms routes are no longer registered', function () {
    expect(Route::has('reception.lab-tracking'))->toBeFalse()
        ->and(Route::has('reception.lab-tracking.report'))->toBeFalse()
        ->and(Route::has('reception.ultrasound'))->toBeFalse()
        ->and(Route::has('reception.ultrasound.print'))->toBeFalse()
        ->and(Route::has('reception.rooms'))->toBeFalse();
});

test('lab tracking ultrasound and rooms are removed from page access registry', function () {
    $pages = array_keys(config('pages.pages'));

    expect($pages)->not->toContain('reception.lab-tracking')
        ->and($pages)->not->toContain('reception.lab-tracking.report')
        ->and($pages)->not->toContain('reception.ultrasound')
        ->and($pages)->not->toContain('reception.ultrasound.print')
        ->and($pages)->not->toContain('reception.rooms');

    $receptionDefaults = config('pages.defaults.'.UserRole::Receptionist->value);

    expect($receptionDefaults)->not->toContain('reception.lab-tracking')
        ->and($receptionDefaults)->not->toContain('reception.ultrasound')
        ->and($receptionDefaults)->not->toContain('reception.rooms');
});

test('lab entry route remains available', function () {
    expect(Route::has('reception.lab-entry'))->toBeTrue();
});

test('rooms and ultrasound report tables remain available', function () {
    expect(Schema::hasTable('rooms'))->toBeTrue()
        ->and(Schema::hasTable('ultrasound_reports'))->toBeTrue();

    expect(Room::factory()->create())->toBeInstanceOf(Room::class)
        ->and(UltrasoundReport::factory()->create())->toBeInstanceOf(UltrasoundReport::class);
});

test('removed page urls return not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/reception/lab-tracking')->assertNotFound();
    $this->actingAs($user)->get('/reception/ultrasound')->assertNotFound();
    $this->actingAs($user)->get('/reception/rooms')->assertNotFound();
});
