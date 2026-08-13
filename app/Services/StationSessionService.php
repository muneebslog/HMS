<?php

namespace App\Services;

use App\Enums\StationType;
use App\Models\HealthAide;
use App\Models\StationSession;

class StationSessionService
{
    /**
     * Record or refresh a station login for the given health aide.
     */
    public function touch(StationType $station, HealthAide $aide): StationSession
    {
        $now = now();

        return StationSession::query()->updateOrCreate(
            ['station' => $station],
            [
                'health_aide_id' => $aide->id,
                'authenticated_at' => $now,
                'expires_at' => $now->copy()->addMinutes(HealthAidePinSession::TTL_MINUTES),
                'last_seen_at' => $now,
            ]
        );
    }

    /**
     * Extend an active station session after a successful action.
     */
    public function bump(StationType $station, HealthAide $aide): StationSession
    {
        $now = now();
        $session = StationSession::query()->firstOrNew(['station' => $station]);

        if ($session->health_aide_id !== $aide->id || $session->authenticated_at === null) {
            $session->authenticated_at = $now;
        }

        $session->health_aide_id = $aide->id;
        $session->expires_at = $now->copy()->addMinutes(HealthAidePinSession::TTL_MINUTES);
        $session->last_seen_at = $now;
        $session->save();

        return $session;
    }

    /**
     * Clear the login for a station.
     */
    public function clear(StationType $station): void
    {
        StationSession::query()->updateOrCreate(
            ['station' => $station],
            [
                'health_aide_id' => null,
                'authenticated_at' => null,
                'expires_at' => null,
                'last_seen_at' => null,
            ]
        );
    }

    /**
     * Get the current session row for a station.
     */
    public function forStation(StationType $station): ?StationSession
    {
        return StationSession::query()
            ->with('healthAide')
            ->where('station', $station)
            ->first();
    }
}
