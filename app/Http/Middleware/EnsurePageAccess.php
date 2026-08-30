<?php

namespace App\Http\Middleware;

use App\Services\PageAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePageAccess
{
    public function __construct(public PageAccessService $pageAccess) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return $next($request);
        }

        if ($this->pageAccess->canAccess($user, $routeName)) {
            return $next($request);
        }

        abort(403);
    }
}
