<?php

namespace App\Http\Controllers\Display;

use App\Http\Controllers\Controller;
use App\Services\StationDeviceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StationHomeController extends Controller
{
    /**
     * Send a registered station PC to its station screen.
     */
    public function __invoke(Request $request, StationDeviceService $stationDevices): Response
    {
        $station = $stationDevices->resolve($request);
        $homeRouteName = $station?->homeRouteName();

        if ($homeRouteName === null) {
            return response()->view('pages.display.station-unregistered', [
                'station' => $station,
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route($homeRouteName);
    }
}
