<?php

use App\Models\HealthAide;
use App\Services\HealthAidePinSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('valid pin unlocks a health aide session', function () {
    $aide = HealthAide::factory()->create(['pin' => '1234']);
    $session = app(HealthAidePinSession::class);

    $result = $session->attempt('1234');

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($aide->id)
        ->and($session->check())->toBeTrue()
        ->and($session->current()?->id)->toBe($aide->id);
});

test('wrong pin does not unlock a session', function () {
    HealthAide::factory()->create(['pin' => '1234']);
    $session = app(HealthAidePinSession::class);

    expect($session->attempt('9999'))->toBeNull()
        ->and($session->check())->toBeFalse();
});

test('inactive aide pin cannot unlock', function () {
    HealthAide::factory()->inactive()->create(['pin' => '1234']);
    $session = app(HealthAidePinSession::class);

    expect($session->attempt('1234'))->toBeNull();
});

test('pin session expires after 10 minutes', function () {
    HealthAide::factory()->create(['pin' => '1234']);
    $session = app(HealthAidePinSession::class);

    Carbon::setTestNow('2026-08-05 10:00:00');
    $session->attempt('1234');
    expect($session->check())->toBeTrue();

    Carbon::setTestNow('2026-08-05 10:09:59');
    expect($session->check())->toBeTrue();

    Carbon::setTestNow('2026-08-05 10:10:00');
    expect($session->check())->toBeFalse()
        ->and($session->current())->toBeNull();

    Carbon::setTestNow();
});

test('forget clears the pin session', function () {
    HealthAide::factory()->create(['pin' => '1234']);
    $session = app(HealthAidePinSession::class);
    $session->attempt('1234');
    $session->forget();

    expect($session->check())->toBeFalse();
});

test('pin is hashed when stored', function () {
    $aide = HealthAide::factory()->create(['pin' => '4321']);

    expect(Hash::check('4321', $aide->fresh()->pin))->toBeTrue()
        ->and($aide->fresh()->pin)->not->toBe('4321');
});

test('pinIsTaken detects duplicate active pins', function () {
    HealthAide::factory()->create(['pin' => '1111']);

    expect(HealthAide::pinIsTaken('1111'))->toBeTrue()
        ->and(HealthAide::pinIsTaken('2222'))->toBeFalse();
});
