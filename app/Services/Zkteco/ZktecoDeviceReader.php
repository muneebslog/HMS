<?php

namespace App\Services\Zkteco;

use App\Models\AttendanceDevice;

class ZktecoDeviceReader implements AttendanceDeviceReader
{
    public function __construct(private float $timeout = 10.0) {}

    public function read(AttendanceDevice $device): array
    {
        $client = new ZktecoClient($device->ip_address, $device->port, $device->comm_key, $this->timeout);
        $client->connect();

        try {
            $client->disableDevice();

            $users = $client->getUsers();
            $punches = $client->getAttendance(array_column($users, 'user_id', 'uid'));
        } finally {
            try {
                $client->enableDevice();
            } finally {
                $client->disconnect();
            }
        }

        return ['users' => $users, 'punches' => $punches];
    }
}
