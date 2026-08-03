<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SupervisorChecklistService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('supervisor:check-missing-checklists')]
#[Description('Notify admins when receptionists have not submitted a checklist for the previous hour block.')]
class CheckMissingSupervisorChecklists extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SupervisorChecklistService $checklistService, NotificationService $notificationService): int
    {
        $block = $checklistService->previousCompletedBlock();

        $receptionists = User::where('role', UserRole::Receptionist)->orderBy('name')->get();

        if ($receptionists->isEmpty()) {
            $this->info('No receptionists found.');

            return self::SUCCESS;
        }

        $notifiedCount = 0;

        foreach ($receptionists as $receptionist) {
            if ($checklistService->hasEntryForBlock($receptionist, $block['start'], $block['end'])) {
                continue;
            }

            $notification = $notificationService->notifySupervisorChecklistMissing(
                $receptionist,
                $block['start'],
                $block['end']
            );

            if ($notification !== null) {
                $notifiedCount++;
                $this->info("Notified: {$receptionist->name} missing block {$block['start']->format('H:i')} - {$block['end']->format('H:i')}.");
            }
        }

        $this->info("Done. {$notifiedCount} notification(s) sent.");

        return self::SUCCESS;
    }
}
