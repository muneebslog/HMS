<?php

namespace App\Console\Commands;

use App\Services\ZktecoSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('attendance:probe-device')]
#[Description('Probe the ZKTeco device and print recent raw attendance rows.')]
class ProbeAttendanceDevice extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ZktecoSyncService $syncService): int
    {
        $rows = $syncService->probe();

        if ($rows === []) {
            $this->warn('No attendance rows returned from the device.');

            return self::SUCCESS;
        }

        $this->table(
            ['User ID', 'UID', 'Recorded At', 'Verify', 'Punch State', 'Label'],
            collect($rows)->map(fn (array $row) => [
                $row['user_id'],
                $row['uid'] ?? '-',
                $row['recorded_at'],
                $row['verify_mode'],
                $row['punch_state'],
                $row['punch_state_label'],
            ])->all(),
        );

        return self::SUCCESS;
    }
}
