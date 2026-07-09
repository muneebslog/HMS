<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ForceHttpsForDomain
{
    /**
     * Force HTTPS URL generation when the request is served via the public
     * Cloudflare Tunnel domain. Local IP access remains on HTTP.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() === config('app.force_https_host')) {
            URL::forceScheme('https');
        }

        return $next($request);
    }
}
