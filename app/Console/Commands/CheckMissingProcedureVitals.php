<?php

namespace App\Console\Commands;

use App\Models\Procedure;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('procedures:check-missing-vitals')]
#[Description('Notify admins when admitted procedures are missing hourly vitals or fetal heart readings.')]
class CheckMissingProcedureVitals extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService): int
    {
        $reference = now();
        $notified = 0;

        Procedure::query()
            ->onWard()
            ->with(['patient', 'procedureType', 'room'])
            ->orderBy('id')
            ->each(function (Procedure $procedure) use ($notificationService, $reference, &$notified): void {
                $missingVitals = $procedure->isVitalsOverdue($reference);
                $missingFhr = $procedure->isFetalHeartOverdue($reference);

                if (! $missingVitals && ! $missingFhr) {
                    return;
                }

                $notification = $notificationService->notifyProcedureVitalsMissing(
                    $procedure,
                    $missingVitals,
                    $missingFhr,
                    $reference->copy()->subHour()->startOfHour(),
                    $reference->copy()->subHour()->endOfHour()
                );

                if ($notification !== null) {
                    $notified++;
                    $this->info("Notified missing readings for procedure #{$procedure->id}.");
                }
            });

        $this->info("Done. {$notified} notification(s) sent.");

        return self::SUCCESS;
    }
}
