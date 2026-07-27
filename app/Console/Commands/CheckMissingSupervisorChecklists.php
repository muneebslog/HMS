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
#[Description('Notify admins when supervisors have not submitted a checklist for the previous hour block.')]
class CheckMissingSupervisorChecklists extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SupervisorChecklistService $checklistService, NotificationService $notificationService): int
    {
        $block = $checklistService->previousCompletedBlock();

        $supervisors = User::where('role', UserRole::Supervisor)->orderBy('name')->get();

        if ($supervisors->isEmpty()) {
            $this->info('No supervisors found.');

            return self::SUCCESS;
        }

        $notifiedCount = 0;

        foreach ($supervisors as $supervisor) {
            if ($checklistService->hasEntryForBlock($supervisor, $block['start'], $block['end'])) {
                continue;
            }

            $notification = $notificationService->notifySupervisorChecklistMissing(
                $supervisor,
                $block['start'],
                $block['end']
            );

            if ($notification !== null) {
                $notifiedCount++;
                $this->info("Notified: {$supervisor->name} missing block {$block['start']->format('H:i')} - {$block['end']->format('H:i')}.");
            }
        }

        $this->info("Done. {$notifiedCount} notification(s) sent.");

        return self::SUCCESS;
    }
}
