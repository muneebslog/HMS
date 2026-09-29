<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGynecologist
{
    /**
     * Allow only admins and users linked to a doctor profile flagged as a gynecologist.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! $user->isAdmin() && ! $user->doctor?->isGynecologist()) {
            abort(403);
        }

        return $next($request);
    }
}
