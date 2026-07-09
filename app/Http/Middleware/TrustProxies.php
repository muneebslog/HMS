<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for the application.
     *
     * Use '*' because Cloudflare Zero Trust Tunnel connections originate from
     * the local cloudflared daemon; the exact upstream IP is not known in
     * advance and can change.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

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
}
