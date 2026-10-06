<?php

namespace App\Services;

use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class StationDeviceService
{
    public const COOKIE = 'hms_station';

    /**
     * Minimum seconds between heartbeat writes for the same station.
     */
    public const HEARTBEAT_INTERVAL_SECONDS = 30;

    /**
     * Request attribute holding the resolved station for the current request.
     */
    public const REQUEST_ATTRIBUTE = 'station';

    /**
     * Bind a new device token to the station and return its plain value.
     *
     * Any PC previously registered to the station loses access.
     */
    public function issueToken(Station $station): string
    {
        $token = Str::random(64);

        $station->forceFill([
            'device_token_hash' => hash('sha256', $token),
            'registered_at' => now(),
            'last_seen_at' => null,
        ])->save();

        return $token;
    }

    /**
     * Build the long-lived cookie that marks this browser as the station PC.
     */
    public function makeCookie(string $token, Request $request): Cookie
    {
        return cookie()->forever(self::COOKIE, $token, secure: $request->isSecure(), sameSite: 'lax');
    }

    /**
     * Build a cookie that removes the station marker from this browser.
     */
    public function forgetCookie(): Cookie
    {
        return cookie()->forget(self::COOKIE);
    }

    /**
     * Detach the registered PC from the station.
     */
    public function unregister(Station $station): void
    {
        $station->forceFill([
            'device_token_hash' => null,
            'registered_at' => null,
            'last_seen_at' => null,
        ])->save();
    }

    /**
     * Resolve the active station that this browser is registered as, if any.
     */
    public function resolve(Request $request): ?Station
    {
        $resolved = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($resolved instanceof Station) {
            return $resolved;
        }

        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $station = Station::query()
            ->where('device_token_hash', hash('sha256', $token))
            ->where('is_active', true)
            ->first();

        if ($station !== null) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $station);
        }

        return $station;
    }

    /**
     * Mark the station as seen, writing at most once per heartbeat interval.
     */
    public function recordHeartbeat(Station $station, Request $request, ?string $routeName = null): void
    {
        $isFresh = $station->last_seen_at !== null
            && $station->last_seen_at->greaterThan(now()->subSeconds(self::HEARTBEAT_INTERVAL_SECONDS));

        if ($isFresh && ($routeName === null || $routeName === $station->last_page)) {
            return;
        }

        $station->forceFill([
            'last_seen_at' => now(),
            'last_page' => $routeName ?? $station->last_page,
            'last_ip' => $request->ip(),
            'last_user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ])->saveQuietly();
    }
}
