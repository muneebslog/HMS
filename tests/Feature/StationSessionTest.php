<?php

use App\Enums\StationType;
use App\Models\HealthAide;
use App\Models\StationSession;
use App\Services\HealthAidePinSession;
use App\Services\StationSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('station session touch upserts aide login for a station', function () {
    $aide = HealthAide::factory()->create();
    $service = app(StationSessionService::class);

    $session = $service->touch(StationType::Er, $aide);

    expect($session->station)->toBe(StationType::Er)
        ->and($session->health_aide_id)->toBe($aide->id)
        ->and($session->isExpired())->toBeFalse()
        ->and($session->minutesRemaining())->toBeGreaterThanOrEqual(9);
});

test('station session bump extends expiry', function () {
    $aide = HealthAide::factory()->create();
    $service = app(StationSessionService::class);

    Carbon::setTestNow('2026-08-13 10:00:00');
    $service->touch(StationType::Drip, $aide);

    Carbon::setTestNow('2026-08-13 10:05:00');
    $bumped = $service->bump(StationType::Drip, $aide);

    expect($bumped->expires_at->equalTo(now()->addMinutes(HealthAidePinSession::TTL_MINUTES)))->toBeTrue()
        ->and($bumped->last_seen_at->equalTo(now()))->toBeTrue();

    Carbon::setTestNow();
});

test('station session clear removes aide login', function () {
    $aide = HealthAide::factory()->create();
    $service = app(StationSessionService::class);
    $service->touch(StationType::Er, $aide);
    $service->clear(StationType::Er);

    $session = $service->forStation(StationType::Er);

    expect($session?->health_aide_id)->toBeNull()
        ->and($session?->isExpired())->toBeTrue();
});

test('station session reports expired after ttl', function () {
    $session = StationSession::factory()->expired()->create([
        'station' => StationType::Er,
    ]);

    expect($session->isExpired())->toBeTrue()
        ->and($session->minutesRemaining())->toBeNull();
});
