<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxy headers for the application.
     *
     * Cloudflare sends the standard X-Forwarded-* headers, including
     * X-Forwarded-Proto, which is required to detect HTTPS correctly.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX;

    /**
     * Only trust real upstream proxies (e.g. cloudflared on localhost).
     *
     * Trusting '*' treats every LAN client as a proxy, so a kiosk browser
     * sending X-Forwarded-Proto: https on plain HTTP gets a Secure session
     * cookie that is never sent back — every Livewire request then 419s
     * until the browser is fully restarted.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies(): array|string|null
    {
        $proxies = config('app.trusted_proxies', '127.0.0.1,::1');

        if ($proxies === '*' || $proxies === '**') {
            return $proxies;
        }

        return $proxies;
    }
}
