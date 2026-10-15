<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class EnsureHermesMcpAccess
{
    /**
     * Allow the Hermes MCP endpoint only from the internal Docker network and with one of the configured tokens
     * (comma-separated, one per assistant, so each can be revoked on its own).
     *
     * The socket address (REMOTE_ADDR) is checked instead of the proxied client IP, so traffic arriving
     * through the public Cloudflare Tunnel (from loopback) is always refused and forwarded headers can't spoof it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $remoteAddress = (string) $request->server->get('REMOTE_ADDR', '');
        $allowedNetworks = array_filter(array_map('trim', explode(',', (string) config('services.hermes_mcp.allowed_ips'))));

        if ($allowedNetworks === [] || ! IpUtils::checkIp($remoteAddress, $allowedNetworks)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validTokens = array_filter(array_map('trim', explode(',', (string) config('services.hermes_mcp.token'))));
        $matches = array_filter($validTokens, fn (string $validToken): bool => hash_equals($validToken, $token));

        if ($token === '' || $matches === []) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
