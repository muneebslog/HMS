<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyDisplayDevices
{
    /**
     * Handle an incoming request.
     *
     * Redirect legacy smart TV browsers (e.g. Chrome 73 on Android 5.1.1 or
     * Chrome 93 on Android TV) to the plain HTML TV display that does not rely
     * on Livewire, Flux, or Tailwind CSS v4, which requires Chrome 111+.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isLegacyDisplayDevice($request) && ! $request->routeIs('display.tokens.tv*')) {
            return redirect()->route('display.tokens.tv', $request->only(['queue', 'sidebar']));
        }

        return $next($request);
    }

    /**
     * Determine whether the request comes from a legacy display device.
     */
    private function isLegacyDisplayDevice(Request $request): bool
    {
        $userAgent = $request->userAgent();

        if ($userAgent === null) {
            return false;
        }

        $userAgent = strtolower($userAgent);

        return str_contains($userAgent, 'smart_tv')
            || str_contains($userAgent, 'crkey')
            || str_contains($userAgent, 'android 5.1.1')
            || $this->isOldChrome($userAgent);
    }

    /**
     * Detect Chrome versions that are known to be incompatible with the
     * Tailwind CSS v4 styles used by the default display.
     */
    private function isOldChrome(string $userAgent): bool
    {
        if (! str_contains($userAgent, 'chrome/')) {
            return false;
        }

        if (preg_match('/chrome\/(\d+)/', $userAgent, $matches) !== 1) {
            return false;
        }

        $version = (int) $matches[1];

        return $version > 0 && $version < 111;
    }
}
