<?php

namespace App\Services;

use App\Models\HealthAide;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

class HealthAidePinSession
{
    public const SESSION_AIDE_ID = 'health_aide_id';

    public const SESSION_AUTHENTICATED_AT = 'health_aide_authenticated_at';

    public const TTL_MINUTES = 10;

    /**
     * Attempt to unlock a health aide session with a PIN.
     */
    public function attempt(string $pin): ?HealthAide
    {
        $pin = trim($pin);

        if ($pin === '') {
            return null;
        }

        $aides = HealthAide::query()->active()->get();

        foreach ($aides as $aide) {
            if (Hash::check($pin, $aide->pin)) {
                $this->store($aide);

                return $aide;
            }
        }

        return null;
    }

    /**
     * Store a verified health aide in the session.
     */
    public function store(HealthAide $aide): void
    {
        Session::put([
            self::SESSION_AIDE_ID => $aide->id,
            self::SESSION_AUTHENTICATED_AT => now()->toIso8601String(),
        ]);
    }

    /**
     * Get the currently authenticated health aide, or null if expired/missing.
     */
    public function current(): ?HealthAide
    {
        $aideId = Session::get(self::SESSION_AIDE_ID);
        $authenticatedAt = Session::get(self::SESSION_AUTHENTICATED_AT);

        if ($aideId === null || $authenticatedAt === null) {
            return null;
        }

        $authenticatedAt = Carbon::parse($authenticatedAt);

        if ($authenticatedAt->copy()->addMinutes(self::TTL_MINUTES)->lessThanOrEqualTo(now())) {
            $this->forget();

            return null;
        }

        return HealthAide::query()->active()->find($aideId);
    }

    /**
     * Whether a valid health aide PIN session is active.
     */
    public function check(): bool
    {
        return $this->current() !== null;
    }

    /**
     * Clear the health aide PIN session.
     */
    public function forget(): void
    {
        Session::forget([
            self::SESSION_AIDE_ID,
            self::SESSION_AUTHENTICATED_AT,
        ]);
    }

    /**
     * Minutes remaining in the current session, or null if none.
     */
    public function minutesRemaining(): ?int
    {
        $authenticatedAt = Session::get(self::SESSION_AUTHENTICATED_AT);

        if ($authenticatedAt === null) {
            return null;
        }

        $expiresAt = Carbon::parse($authenticatedAt)->addMinutes(self::TTL_MINUTES);
        $remaining = (int) now()->diffInMinutes($expiresAt, false);

        return max(0, $remaining);
    }
}
