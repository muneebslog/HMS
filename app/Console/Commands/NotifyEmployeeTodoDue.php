<?php

namespace App\Console\Commands;

use App\Models\EmployeeTodo;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('employee-todos:notify')]
#[Description('Notify admins about due or overdue employee todos.')]
class NotifyEmployeeTodoDue extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService): int
    {
        $todos = EmployeeTodo::with(['employee', 'creator'])
            ->pending()
            ->overdue()
            ->get();

        $notifiedCount = 0;

        foreach ($todos as $todo) {
            $notification = $notificationService->notifyEmployeeTodoDue($todo);

            if ($notification !== null) {
                $notifiedCount++;
            }
        }

        $this->info("Notified admins about {$notifiedCount} due staff todo(s).");

        return self::SUCCESS;
    }
}
