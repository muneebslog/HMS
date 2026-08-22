<?php

namespace App\Services;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\HealthAide;
use Throwable;
use ZkTeco\Enums\Privilege;
use ZkTeco\Enums\PunchState;
use ZkTeco\Enums\VerifyMode;
use ZkTeco\TCP\Device;
use ZkTeco\Values\AttendanceRecord as DeviceAttendanceRecord;
use ZkTeco\Values\User as DeviceUser;

class ZktecoSyncService
{
    /**
     * Sync attendance punches from the device into HMS.
     *
     * @return array{imported: int, total: int}
     */
    public function sync(?AttendanceDevice $device = null): array
    {
        $device ??= AttendanceDevice::defaultDevice();

        try {
            $records = $this->fetchAttendanceRecords($device);
            $imported = 0;

            foreach ($records as $record) {
                if ($this->importRecord($device, $record)) {
                    $imported++;
                }
            }

            $device->update([
                'last_sync_at' => now(),
                'last_sync_status' => 'success',
                'last_sync_error' => null,
                'consecutive_sync_failures' => 0,
            ]);

            return [
                'imported' => $imported,
                'total' => count($records),
            ];
        } catch (Throwable $exception) {
            $device->update([
                'last_sync_status' => 'failed',
                'last_sync_error' => $exception->getMessage(),
                'consecutive_sync_failures' => $device->consecutive_sync_failures + 1,
            ]);

            throw $exception;
        }
    }

    /**
     * Probe the device and return recent raw attendance rows.
     *
     * @return list<array<string, mixed>>
     */
    public function probe(?AttendanceDevice $device = null): array
    {
        $device ??= AttendanceDevice::defaultDevice();
        $records = $this->fetchAttendanceRecords($device);

        return collect($records)
            ->take(-10)
            ->values()
            ->map(fn (DeviceAttendanceRecord $record) => [
                'user_id' => $record->userId,
                'uid' => $record->uid,
                'recorded_at' => $record->recordedAt->format('Y-m-d H:i:s'),
                'verify_mode' => $record->verifyMode->name,
                'punch_state' => $record->punchState->value,
                'punch_state_label' => $record->punchState->name,
            ])
            ->all();
    }

    /**
     * Enroll a health aide on the biometric device.
     */
    public function enrollHealthAide(HealthAide $healthAide, ?AttendanceDevice $device = null): void
    {
        $device ??= AttendanceDevice::defaultDevice();
        $userId = (string) $healthAide->id;

        $this->withDevice($device, function (Device $zkDevice) use ($healthAide, $userId): void {
            $users = $zkDevice->users()->all();
            $existing = collect($users)->first(fn (DeviceUser $user) => $user->userId === $userId);

            if ($existing !== null) {
                $zkDevice->users()->save(new DeviceUser(
                    uid: $existing->uid,
                    userId: $userId,
                    name: $healthAide->name,
                    privilege: Privilege::User,
                ));

                return;
            }

            $nextUid = collect($users)->max(fn (DeviceUser $user) => $user->uid) ?? 0;
            $nextUid++;

            $zkDevice->users()->save(new DeviceUser(
                uid: $nextUid,
                userId: $userId,
                name: $healthAide->name,
                privilege: Privilege::User,
            ));
        });

        $healthAide->update([
            'device_user_id' => $userId,
            'attendance_enrolled_at' => now(),
        ]);
    }

    /**
     * Test whether the device is reachable.
     */
    public function testConnection(?AttendanceDevice $device = null): bool
    {
        $device ??= AttendanceDevice::defaultDevice();

        $this->withDevice($device, function (Device $zkDevice): void {
            $zkDevice->info()->serialNumber();
        });

        return true;
    }

    /**
     * @return list<DeviceAttendanceRecord>
     */
    public function fetchAttendanceRecords(AttendanceDevice $device): array
    {
        return $this->withDevice($device, fn (Device $zkDevice) => $zkDevice->attendance()->all());
    }

    private function importRecord(AttendanceDevice $device, DeviceAttendanceRecord $record): bool
    {
        $devicePunchUid = ($record->uid ?? 0).'_'.$record->recordedAt->format('Y-m-d H:i:s');
        $healthAide = HealthAide::query()
            ->where('device_user_id', $record->userId)
            ->first();

        $punch = AttendancePunch::query()->firstOrCreate(
            [
                'attendance_device_id' => $device->id,
                'device_punch_uid' => $devicePunchUid,
            ],
            [
                'device_user_id' => $record->userId,
                'health_aide_id' => $healthAide?->id,
                'punched_at' => $record->recordedAt,
                'verify_type' => $this->verifyTypeValue($record->verifyMode),
                'punch_state' => $record->punchState === PunchState::Undefined ? null : $record->punchState->value,
            ],
        );

        if ($healthAide !== null && $punch->health_aide_id === null) {
            $punch->update(['health_aide_id' => $healthAide->id]);
        }

        return $punch->wasRecentlyCreated;
    }

    /**
     * @template T
     *
     * @param  callable(Device): T  $callback
     * @return T
     */
    private function withDevice(AttendanceDevice $device, callable $callback): mixed
    {
        $config = config('attendance.device');
        $zkDevice = new Device(
            host: $device->ip_address,
            port: $device->port,
            commKey: $config['comm_key'] ?? 0,
            timeout: $config['timeout'] ?? 5.0,
        );

        return $zkDevice->session($callback);
    }

    private function verifyTypeValue(VerifyMode $mode): int
    {
        return match ($mode) {
            VerifyMode::Fingerprint => 1,
            VerifyMode::Password => 3,
            VerifyMode::Card => 4,
            VerifyMode::Face => 15,
            VerifyMode::Other => 0,
        };
    }
}
