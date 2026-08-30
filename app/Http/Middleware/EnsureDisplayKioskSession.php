<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDisplayKioskSession
{
    /**
     * Tune session settings for always-on display boards.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('display/*')) {
            return $next($request);
        }

        config([
            'session.lifetime' => config('display.session_lifetime_minutes'),
        ]);

        if ($request->getHost() !== config('app.force_https_host')) {
            config(['session.secure' => false]);
        }

        return $next($request);
    }
}
