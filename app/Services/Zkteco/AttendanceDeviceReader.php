<?php

namespace App\Services\Zkteco;

use App\Models\AttendanceDevice;
use Carbon\CarbonImmutable;

interface AttendanceDeviceReader
{
    /**
     * Download enrolled users and all stored punches from the device.
     *
     * @return array{
     *     users: list<array{uid: int, user_id: string, name: string, privilege: int, card: ?string}>,
     *     punches: list<array{uid: ?int, user_id: string, punched_at: CarbonImmutable, verify_type: int, punch_state: int}>
     * }
     */
    public function read(AttendanceDevice $device): array;
}
