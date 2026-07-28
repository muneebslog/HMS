<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\EmployeeLeave;
use App\Models\EmployeeTodo;
use App\Models\KanbanItem;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\QueueToken;
use App\Models\RoleRequest;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * The ntfy.sh topic endpoint.
     */
    private string $ntfyEndpoint = 'https://ntfy.sh/mmc-hms';

    /**
     * Notify that a token was reserved without a patient phone number.
     */
    public function notifyReservationWithoutPhone(
        User $user,
        QueueToken $token,
        string $patientName,
        int $tokenNumber
    ): AdminNotification {
        $title = __('📵 Token Issued Without Contact Number');
        $message = __(
            'Receptionist :name issued token :number for :patient without a contact number.',
            [
                'name' => $user->name,
                'number' => $tokenNumber,
                'patient' => $patientName,
            ]
        );

        return $this->createAdminNotification(
            $user,
            'reservation_without_phone',
            $title,
            $message,
            route('reception.reservation'),
            [
                'token_id' => $token->id,
                'token_number' => $tokenNumber,
                'patient_id' => $token->patient_id,
                'patient_name' => $patientName,
                'queue_id' => $token->service_queue_id,
            ]
        );
    }

    /**
     * Notify that a lab invoice has in-house tests missing numeric codes.
     *
     * @param  Collection<int, LabInvoiceItem>  $items
     */
    public function notifyLabTestMissingCode(LabInvoice $invoice, $items): AdminNotification
    {
        $user = User::find($invoice->created_by);

        if ($user === null) {
            throw new \RuntimeException('Cannot notify: lab invoice creator not found.');
        }

        $testNames = $items->map(fn ($item) => $item->test_name)->implode(', ');

        $title = __('🧪 Lab Test Missing Code');
        $message = __('Invoice :invoice has in-house tests without numeric codes and were not sent to the lab: :tests.', [
            'invoice' => $invoice->invoice_number,
            'tests' => $testNames,
        ]);

        return $this->createAdminNotification(
            $user,
            'lab_test_missing_code',
            $title,
            $message,
            route('reception.invoices'),
            [
                'lab_invoice_id' => $invoice->id,
                'test_names' => $items->map(fn ($item) => $item->test_name)->all(),
            ]
        );
    }

    /**
     * Notify that a lab case sync failed after all retries.
     */
    public function notifyLabCaseSyncFailed(LabInvoice $invoice, \Throwable $exception): ?AdminNotification
    {
        $alreadyNotified = AdminNotification::where('type', 'lab_case_sync_failed')
            ->whereJsonContains('metadata', ['lab_invoice_id' => $invoice->id])
            ->exists();

        if ($alreadyNotified) {
            return null;
        }

        $user = User::find($invoice->created_by);

        if ($user === null) {
            return null;
        }

        $title = __('❌ Lab Case Sync Failed');
        $message = __('Lab invoice :invoice could not be sent to the lab app after multiple attempts: :error', [
            'invoice' => $invoice->invoice_number,
            'error' => $exception->getMessage(),
        ]);

        return $this->createAdminNotification(
            $user,
            'lab_case_sync_failed',
            $title,
            $message,
            route('reception.invoices'),
            [
                'lab_invoice_id' => $invoice->id,
                'error' => $exception->getMessage(),
            ]
        );
    }

    /**
     * Notify that a shift was opened without an opening balance.
     */
    public function notifyShiftOpenedWithoutBalance(User $user, Shift $shift): AdminNotification
    {
        $title = __('💰 Shift Opened Without Opening Balance');
        $message = __(
            'Receptionist :name opened shift #:shift without adding an opening balance.',
            [
                'name' => $user->name,
                'shift' => $shift->id,
            ]
        );

        return $this->createAdminNotification(
            $user,
            'shift_opened_without_balance',
            $title,
            $message,
            route('reception.shift'),
            [
                'shift_id' => $shift->id,
            ]
        );
    }

    /**
     * Notify that a shift was closed without recording any expenses.
     */
    public function notifyShiftClosedWithoutExpenses(User $user, Shift $shift): AdminNotification
    {
        $title = __('🧾 Shift Closed Without Expenses');
        $message = __(
            'Receptionist :name closed shift #:shift without recording any expenses.',
            [
                'name' => $user->name,
                'shift' => $shift->id,
            ]
        );

        return $this->createAdminNotification(
            $user,
            'shift_closed_without_expenses',
            $title,
            $message,
            route('management.shift-history'),
            [
                'shift_id' => $shift->id,
            ]
        );
    }

    /**
     * Notify that a shift was closed without recording any doctor share payments.
     */
    public function notifyShiftClosedWithoutDoctorPayouts(User $user, Shift $shift): AdminNotification
    {
        $title = __('👨‍⚕️ Shift Closed Without Doctor Share Payments');
        $message = __(
            'Receptionist :name closed shift #:shift without recording any doctor share payments.',
            [
                'name' => $user->name,
                'shift' => $shift->id,
            ]
        );

        return $this->createAdminNotification(
            $user,
            'shift_closed_without_doctor_payouts',
            $title,
            $message,
            route('payout.daily'),
            [
                'shift_id' => $shift->id,
            ]
        );
    }

    /**
     * Notify admins that a new kanban item has been created.
     */
    public function notifyKanbanItemCreated(KanbanItem $item, User $user): AdminNotification
    {
        $title = __('🎯 New Kanban Task Added');
        $message = __("A new task ':title' has been dropped into the :column column! Let's crush it! 💪🔥", [
            'title' => $item->title,
            'column' => $item->status->label(),
        ]);

        return $this->createAdminNotification(
            $user,
            'kanban_item_created',
            $title,
            $message,
            route('admin.kanban'),
            [
                'kanban_item_id' => $item->id,
                'status' => $item->status->value,
            ]
        );
    }

    /**
     * Notify that a kanban item has been moved to a different column.
     */
    public function notifyKanbanItemMoved(KanbanItem $item, User $user): AdminNotification
    {
        $title = __('🚀 Task on the Move');
        $message = __("':title' just slid into the :column column! Keep the momentum going! ⚡", [
            'title' => $item->title,
            'column' => $item->status->label(),
        ]);

        return $this->createAdminNotification(
            $user,
            'kanban_item_moved',
            $title,
            $message,
            route('admin.kanban'),
            [
                'kanban_item_id' => $item->id,
                'status' => $item->status->value,
            ]
        );
    }

    /**
     * Notify that a kanban item has been updated.
     */
    public function notifyKanbanItemUpdated(KanbanItem $item, User $user): AdminNotification
    {
        $title = __('✏️ Kanban Task Updated');
        $message = __("':title' got a fresh edit. Looking sharp! ✨", [
            'title' => $item->title,
        ]);

        return $this->createAdminNotification(
            $user,
            'kanban_item_updated',
            $title,
            $message,
            route('admin.kanban'),
            [
                'kanban_item_id' => $item->id,
                'status' => $item->status->value,
            ]
        );
    }

    /**
     * Notify that a kanban item has been deleted.
     */
    public function notifyKanbanItemDeleted(string $title, User $user): AdminNotification
    {
        $notificationTitle = __('🗑️ Kanban Task Deleted');
        $message = __("':title' has been completed and cleared off the board. Nice work! ✅", [
            'title' => $title,
        ]);

        return $this->createAdminNotification(
            $user,
            'kanban_item_deleted',
            $notificationTitle,
            $message,
            route('admin.kanban')
        );
    }

    /**
     * Notify admins that a user has submitted a role request.
     */
    public function notifyRoleRequestSubmitted(RoleRequest $request, User $user): AdminNotification
    {
        $title = __('📝 New Role Request');
        $message = __(
            ':name has requested the :role role.',
            [
                'name' => $user->name,
                'role' => $request->requested_role->label(),
            ]
        );

        return $this->createAdminNotification(
            $user,
            'role_request_submitted',
            $title,
            $message,
            route('admin.users'),
            [
                'role_request_id' => $request->id,
                'requested_role' => $request->requested_role->value,
            ]
        );
    }

    /**
     * Notify admins that a new employee leave has been recorded.
     */
    public function notifyEmployeeLeaveCreated(EmployeeLeave $leave, User $user): AdminNotification
    {
        $title = __('📅 New Leave Recorded');
        $message = __(':name is on leave on :date.', [
            'name' => $leave->employee_name,
            'date' => $leave->leave_date->format('F j, Y'),
        ]);

        if ($leave->replacement_name !== null) {
            $message .= ' '.__('Replaced by :replacement.', ['replacement' => $leave->replacement_name]);
        }

        return $this->createAdminNotification(
            $user,
            'employee_leave_created',
            $title,
            $message,
            route('admin.leave-calendar'),
            [
                'employee_leave_id' => $leave->id,
                'leave_date' => $leave->leave_date->toDateString(),
            ]
        );
    }

    /**
     * Notify admins that a new employee todo has been created.
     */
    public function notifyEmployeeTodoCreated(EmployeeTodo $todo, User $user): AdminNotification
    {
        $title = __('📝 New Staff Todo: :name', ['name' => $todo->employee->name]);
        $message = __("':todo' has been added for :name with due date :due.", [
            'todo' => $todo->title,
            'name' => $todo->employee->name,
            'due' => $todo->due_date->format('Y-m-d'),
        ]);

        return $this->createAdminNotification(
            $user,
            'employee_todo_created',
            $title,
            $message,
            route('admin.employees.profile', $todo->employee),
            [
                'employee_todo_id' => $todo->id,
                'employee_id' => $todo->employee_id,
            ]
        );
    }

    /**
     * Notify admins that an employee todo is due or overdue.
     */
    public function notifyEmployeeTodoDue(EmployeeTodo $todo): ?AdminNotification
    {
        $alreadyNotified = AdminNotification::where('type', 'employee_todo_due')
            ->whereJsonContains('metadata', ['employee_todo_id' => $todo->id])
            ->exists();

        if ($alreadyNotified) {
            return null;
        }

        $employee = $todo->employee;
        $creator = $todo->creator;

        if ($employee === null || $creator === null) {
            return null;
        }

        $isOverdue = $todo->due_date->isPast();

        $title = $isOverdue
            ? __('⏰ Staff Todo Overdue: :name', ['name' => $employee->name])
            : __('⏰ Staff Todo Due Today: :name', ['name' => $employee->name]);

        $message = $isOverdue
            ? __("':todo' for :name was due on :due and is now overdue.", [
                'todo' => $todo->title,
                'name' => $employee->name,
                'due' => $todo->due_date->format('Y-m-d'),
            ])
            : __("':todo' for :name is due today (:due).", [
                'todo' => $todo->title,
                'name' => $employee->name,
                'due' => $todo->due_date->format('Y-m-d'),
            ]);

        return $this->createAdminNotification(
            $creator,
            'employee_todo_due',
            $title,
            $message,
            route('admin.employees.profile', $employee),
            [
                'employee_todo_id' => $todo->id,
                'employee_id' => $employee->id,
            ]
        );
    }

    /**
     * Notify admins that a supervisor has not submitted a checklist for a block.
     */
    public function notifySupervisorChecklistMissing(
        User $supervisor,
        CarbonInterface $blockStart,
        CarbonInterface $blockEnd
    ): ?AdminNotification {
        $alreadyNotified = AdminNotification::where('type', 'supervisor_checklist_missing')
            ->whereJsonContains('metadata', ['supervisor_id' => $supervisor->id])
            ->whereJsonContains('metadata', ['block_starts_at' => $blockStart->toDateTimeString()])
            ->exists();

        if ($alreadyNotified) {
            return null;
        }

        $title = __('⏰ Supervisor Checklist Missing');
        $message = __(
            'Supervisor :name has not submitted the checklist for the :start - :end block.',
            [
                'name' => $supervisor->name,
                'start' => $blockStart->format('H:i'),
                'end' => $blockEnd->format('H:i'),
            ]
        );

        return $this->createAdminNotification(
            $supervisor,
            'supervisor_checklist_missing',
            $title,
            $message,
            route('admin.supervisor-checklist'),
            [
                'supervisor_id' => $supervisor->id,
                'block_starts_at' => $blockStart->toDateTimeString(),
                'block_ends_at' => $blockEnd->toDateTimeString(),
            ]
        );
    }

    /**
     * Create an in-app admin notification and send an ntfy.sh push.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function createAdminNotification(
        User $user,
        string $type,
        string $title,
        string $message,
        string $actionableUrl,
        array $metadata = []
    ): AdminNotification {
        $notification = AdminNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'actionable_url' => $actionableUrl,
            'metadata' => $metadata,
        ]);

        $this->sendNtfyPush($title, $message, $actionableUrl);

        return $notification;
    }

    /**
     * Send a push notification via ntfy.sh.
     */
    private function sendNtfyPush(string $title, string $message, string $actionUrl): void
    {
        try {
            $headers = [
                'Content-Type: text/plain',
                "Title: {$title}",
                "Actions: view, Open, {$actionUrl}",
            ];

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\n", $headers),
                    'content' => $message,
                    'timeout' => 10,
                ],
            ]);

            $result = @file_get_contents($this->ntfyEndpoint, false, $context);

            if ($result === false) {
                Log::warning('ntfy.sh push notification failed to send.', [
                    'endpoint' => $this->ntfyEndpoint,
                    'title' => $title,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send ntfy.sh push notification.', [
                'endpoint' => $this->ntfyEndpoint,
                'title' => $title,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
