<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoleAssigned
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $isPendingRoute = $request->routeIs('pending-role');

        if ($user->role === UserRole::User) {
            if (! $isPendingRoute) {
                return redirect()->route('pending-role');
            }

            return $next($request);
        }

        if ($isPendingRoute) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
