<?php

namespace App\Console\Commands;

use App\Models\AttendanceDevice;
use App\Services\AttendanceSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('attendance:sync {device? : Only sync this device id}')]
#[Description('Import users and punches from the ZKTeco attendance machines.')]
class SyncAttendanceDevices extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AttendanceSyncService $attendanceSyncService): int
    {
        $devices = AttendanceDevice::query()
            ->active()
            ->when($this->argument('device'), fn ($query, $deviceId) => $query->whereKey($deviceId))
            ->get();

        $failed = false;

        foreach ($devices as $device) {
            try {
                $result = $attendanceSyncService->sync($device);
                $this->info("{$device->name}: {$result['users']} users ({$result['new_users']} new), {$result['new_punches']} new punches.");
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("{$device->name}: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
