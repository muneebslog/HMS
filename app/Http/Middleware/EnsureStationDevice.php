<?php

namespace App\Http\Middleware;

use App\Services\PageAccessService;
use App\Services\StationDeviceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStationDevice
{
    public function __construct(
        public StationDeviceService $stationDevices,
        public PageAccessService $pageAccess,
    ) {}

    /**
     * Allow station pages only on registered station PCs or for signed-in staff with page access.
     *
     * Registered PCs check in on every request (including Livewire polls) so we can tell
     * whether the station is currently active.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        $station = $this->stationDevices->resolve($request);

        if ($station !== null && ($routeName === null || $station->canOpen($routeName))) {
            $this->stationDevices->recordHeartbeat($station, $request, $routeName);

            return $next($request);
        }

        $user = $request->user();

        if ($user !== null && ($routeName === null || $this->pageAccess->canAccess($user, $routeName))) {
            return $next($request);
        }

        return response()->view('pages.display.station-unregistered', [
            'station' => $station,
        ], Response::HTTP_FORBIDDEN);
    }
}
