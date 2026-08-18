<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\NurseQuestionnaire;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\NurseQuestionnaireService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('nurse-questionnaires:check-missing')]
#[Description('Notify admins when incharge nurses have not submitted questionnaires for the previous interval block.')]
class CheckMissingNurseQuestionnaires extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NurseQuestionnaireService $questionnaireService, NotificationService $notificationService): int
    {
        $questionnaires = NurseQuestionnaire::query()
            ->active()
            ->whereHas('questions', fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();

        if ($questionnaires->isEmpty()) {
            $this->info('No active questionnaires found.');

            return self::SUCCESS;
        }

        $nurses = User::where('role', UserRole::InchargeNurse)->orderBy('name')->get();

        if ($nurses->isEmpty()) {
            $this->info('No incharge nurses found.');

            return self::SUCCESS;
        }

        $notifiedCount = 0;

        foreach ($questionnaires as $questionnaire) {
            $block = $questionnaireService->previousCompletedBlock($questionnaire);

            foreach ($nurses as $nurse) {
                if ($questionnaireService->hasEntryForBlock($nurse, $questionnaire, $block['start'], $block['end'])) {
                    continue;
                }

                $notification = $notificationService->notifyNurseQuestionnaireMissing(
                    $nurse,
                    $questionnaire,
                    $block['start'],
                    $block['end']
                );

                if ($notification !== null) {
                    $notifiedCount++;
                    $this->info("Notified: {$nurse->name} missing {$questionnaire->name} block {$block['start']->format('H:i')} - {$block['end']->format('H:i')}.");
                }
            }
        }

        $this->info("Done. {$notifiedCount} notification(s) sent.");

        return self::SUCCESS;
    }
}
