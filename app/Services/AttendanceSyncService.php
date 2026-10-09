<?php

namespace App\Services;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Models\HealthAide;
use App\Services\Zkteco\AttendanceDeviceReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class AttendanceSyncService
{
    public function __construct(private AttendanceDeviceReader $reader) {}

    /**
     * Import users and punches from the device into HMS.
     *
     * @return array{users: int, new_users: int, punches: int, new_punches: int}
     */
    public function sync(AttendanceDevice $device): array
    {
        try {
            $snapshot = $this->reader->read($device);

            $result = DB::transaction(function () use ($device, $snapshot): array {
                $newUsers = $this->importUsers($device, $snapshot['users']);
                $newPunches = $this->importPunches($device, $snapshot['punches']);

                return [
                    'users' => count($snapshot['users']),
                    'new_users' => $newUsers,
                    'punches' => count($snapshot['punches']),
                    'new_punches' => $newPunches,
                ];
            });

            $device->update([
                'last_synced_at' => now(),
                'last_sync_status' => 'success',
                'last_sync_error' => null,
            ]);

            return $result;
        } catch (Throwable $exception) {
            $device->update([
                'last_sync_status' => 'failed',
                'last_sync_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Link a device user to a health aide, replacing any previous link on that device.
     */
    public function link(AttendanceDeviceUser $deviceUser, HealthAide $healthAide): void
    {
        DB::transaction(function () use ($deviceUser, $healthAide): void {
            AttendanceDeviceUser::query()
                ->where('attendance_device_id', $deviceUser->attendance_device_id)
                ->where('health_aide_id', $healthAide->id)
                ->whereKeyNot($deviceUser->id)
                ->update(['health_aide_id' => null]);

            $deviceUser->update(['health_aide_id' => $healthAide->id]);
        });
    }

    public function unlink(AttendanceDeviceUser $deviceUser): void
    {
        $deviceUser->update(['health_aide_id' => null]);
    }

    /**
     * @param  list<array{uid: int, user_id: string, name: string, privilege: int, card: ?string}>  $users
     */
    private function importUsers(AttendanceDevice $device, array $users): int
    {
        $created = 0;

        foreach ($users as $user) {
            $deviceUser = AttendanceDeviceUser::query()->updateOrCreate(
                [
                    'attendance_device_id' => $device->id,
                    'device_user_id' => $user['user_id'],
                ],
                [
                    'device_uid' => $user['uid'],
                    'name' => $user['name'] !== '' ? $user['name'] : null,
                    'privilege' => $user['privilege'],
                    'card_number' => $user['card'],
                    'last_seen_at' => now(),
                ],
            );

            if ($deviceUser->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  list<array{uid: ?int, user_id: string, punched_at: CarbonImmutable, verify_type: int, punch_state: int}>  $punches
     */
    private function importPunches(AttendanceDevice $device, array $punches): int
    {
        if ($punches === []) {
            return 0;
        }

        $deviceUserIds = AttendanceDeviceUser::query()
            ->where('attendance_device_id', $device->id)
            ->pluck('id', 'device_user_id');

        $now = now();
        $rows = array_map(fn (array $punch): array => [
            'attendance_device_id' => $device->id,
            'attendance_device_user_id' => $deviceUserIds[$punch['user_id']] ?? null,
            'device_user_id' => $punch['user_id'],
            'punched_at' => $punch['punched_at']->format('Y-m-d H:i:s'),
            'verify_type' => $punch['verify_type'],
            'punch_state' => $punch['punch_state'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $punches);

        $inserted = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $inserted += AttendancePunch::query()->insertOrIgnore($chunk);
        }

        // Punches imported before their user existed locally get attached now.
        AttendancePunch::query()
            ->where('attendance_device_id', $device->id)
            ->whereNull('attendance_device_user_id')
            ->get(['id', 'device_user_id'])
            ->groupBy('device_user_id')
            ->each(function ($group, string $deviceUserId) use ($deviceUserIds): void {
                if (isset($deviceUserIds[$deviceUserId])) {
                    AttendancePunch::query()
                        ->whereKey($group->pluck('id'))
                        ->update(['attendance_device_user_id' => $deviceUserIds[$deviceUserId]]);
                }
            });

        return $inserted;
    }
}
