<?php

namespace App\Services;

use App\Events\AdminReportMessagePosted;
use App\Events\ReceptionMemoPosted;
use App\Models\AdminNotification;
use App\Models\AdminReport;
use App\Models\AdminReportMessage;
use App\Models\EmployeeLeave;
use App\Models\EmployeeTodo;
use App\Models\KanbanItem;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\QueueToken;
use App\Models\ReceptionMemo;
use App\Models\RoleRequest;
use App\Models\Shift;
use App\Models\SupervisorChecklistEntry;
use App\Models\SupervisorChecklistResponse;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Default ntfy priority for existing admin alerts.
     */
    private const DEFAULT_PRIORITY = 3;

    /**
     * ntfy priority for admin-facing report alerts.
     */
    private const ADMIN_PRIORITY = 5;

    /**
     * ntfy priority for receptionist-facing alerts.
     */
    private const RECEPTION_PRIORITY = 4;

    /**
     * ntfy priority for reception memo alerts.
     */
    private const MEMO_PRIORITY = 5;

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
     * Notify that an admin updated a queue token patient's details.
     *
     * @param  array{name: ?string, phone: ?string}  $before
     * @param  array{name: ?string, phone: ?string}  $after
     */
    public function notifyTokenPatientUpdated(
        User $admin,
        QueueToken $token,
        array $before,
        array $after
    ): AdminNotification {
        $title = __('✏️ Token Patient Details Updated');
        $message = __(
            'Admin :name updated patient details for token :number.',
            [
                'name' => $admin->name,
                'number' => $token->token_number,
            ]
        );

        return $this->createAdminNotification(
            $admin,
            'token_patient_updated',
            $title,
            $message,
            route('reception.queue'),
            [
                'token_id' => $token->id,
                'token_number' => $token->token_number,
                'queue_id' => $token->service_queue_id,
                'patient_id' => $token->patient_id,
                'before' => $before,
                'after' => $after,
            ]
        );
    }

    /**
     * Notify that an admin reversed a queue token status.
     */
    public function notifyTokenStatusReversed(
        User $admin,
        QueueToken $token,
        string $fromStatus,
        string $toStatus
    ): AdminNotification {
        $title = __('↩️ Token Status Reversed');
        $message = __(
            'Admin :name changed token :number from :from to :to.',
            [
                'name' => $admin->name,
                'number' => $token->token_number,
                'from' => $fromStatus,
                'to' => $toStatus,
            ]
        );

        return $this->createAdminNotification(
            $admin,
            'token_status_reversed',
            $title,
            $message,
            route('reception.queue'),
            [
                'token_id' => $token->id,
                'token_number' => $token->token_number,
                'queue_id' => $token->service_queue_id,
                'before' => ['status' => $fromStatus],
                'after' => ['status' => $toStatus],
            ]
        );
    }

    /**
     * Notify that an admin cancelled an invoice attached to a token.
     */
    public function notifyInvoiceCancelled(
        User $admin,
        QueueToken $token,
        string $invoiceNumber
    ): AdminNotification {
        $title = __('🧾 Invoice Cancelled');
        $message = __(
            'Admin :name cancelled invoice :invoice for token :number.',
            [
                'name' => $admin->name,
                'invoice' => $invoiceNumber,
                'number' => $token->token_number,
            ]
        );

        return $this->createAdminNotification(
            $admin,
            'invoice_cancelled',
            $title,
            $message,
            route('reception.invoices'),
            [
                'token_id' => $token->id,
                'token_number' => $token->token_number,
                'queue_id' => $token->service_queue_id,
                'invoice_number' => $invoiceNumber,
            ]
        );
    }

    /**
     * Notify that an admin reverted (removed) a reserved token.
     *
     * @param  array{token_id: int, token_number: int, queue_id: int, patient_id: ?int, patient_name: ?string}  $snapshot
     */
    public function notifyReservationReverted(User $admin, array $snapshot): AdminNotification
    {
        $title = __('🗑️ Reservation Reverted');
        $message = __(
            'Admin :name removed reserved token :number for :patient.',
            [
                'name' => $admin->name,
                'number' => $snapshot['token_number'],
                'patient' => $snapshot['patient_name'] ?? __('Unknown'),
            ]
        );

        return $this->createAdminNotification(
            $admin,
            'reservation_reverted',
            $title,
            $message,
            route('reception.queue'),
            [
                'token_id' => $snapshot['token_id'],
                'token_number' => $snapshot['token_number'],
                'queue_id' => $snapshot['queue_id'],
                'patient_id' => $snapshot['patient_id'],
                'patient_name' => $snapshot['patient_name'],
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
     * Notify admins that a supervisor submitted a checklist with "No" answers.
     *
     * @param  Collection<int, SupervisorChecklistResponse>  $noResponses
     */
    public function notifySupervisorChecklistSubmitted(
        User $supervisor,
        SupervisorChecklistEntry $entry,
        Collection $noResponses
    ): AdminNotification {
        $title = __('⚠️ Supervisor Checklist Submitted With No Answers');

        $items = $noResponses->map(function (SupervisorChecklistResponse $response): string {
            $text = $response->question->question_text;

            return $response->remarks
                ? "- {$text} ({$response->remarks})"
                : "- {$text}";
        })->implode("\n");

        $message = __(
            "Supervisor :name submitted the checklist for :start - :end with the following No answers:\n:items",
            [
                'name' => $supervisor->name,
                'start' => $entry->block_starts_at->format('H:i'),
                'end' => $entry->block_ends_at->format('H:i'),
                'items' => $items,
            ]
        );

        return $this->createAdminNotification(
            $supervisor,
            'supervisor_checklist_no_answers',
            $title,
            $message,
            route('admin.supervisor-checklist'),
            [
                'supervisor_id' => $supervisor->id,
                'entry_id' => $entry->id,
                'block_starts_at' => $entry->block_starts_at->toDateTimeString(),
                'block_ends_at' => $entry->block_ends_at->toDateTimeString(),
                'no_response_ids' => $noResponses->pluck('id')->all(),
            ]
        );
    }

    /**
     * Notify that a report-to-admin thread was created.
     */
    public function notifyAdminReportCreated(AdminReport $report, AdminReportMessage $message, User $user): ?AdminNotification
    {
        $title = __('New Report to Admin');
        $body = __(
            ':name opened a report: :subject',
            [
                'name' => $user->name,
                'subject' => $report->subject,
            ]
        );
        $actionUrl = route('admin.reports');
        $metadata = [
            'admin_report_id' => $report->id,
            'admin_report_message_id' => $message->id,
            'subject' => $report->subject,
        ];

        if ($user->isAdmin()) {
            $this->sendNtfyPush(
                $title,
                $body,
                $actionUrl,
                self::RECEPTION_PRIORITY,
                $this->receptionTopic()
            );

            $this->broadcastSafely(new AdminReportMessagePosted($report, $user, true));

            return null;
        }

        $notification = $this->createAdminNotification(
            $user,
            'admin_report_created',
            $title,
            $body,
            $actionUrl,
            $metadata,
            self::ADMIN_PRIORITY,
            $this->adminTopic()
        );

        $this->broadcastSafely(new AdminReportMessagePosted($report, $user, true));

        return $notification;
    }

    /**
     * Notify that a report-to-admin thread received a reply.
     */
    public function notifyAdminReportReplied(AdminReport $report, AdminReportMessage $message, User $user): ?AdminNotification
    {
        $title = __('Report Reply');
        $body = __(
            ':name replied on: :subject',
            [
                'name' => $user->name,
                'subject' => $report->subject,
            ]
        );
        $actionUrl = route('admin.reports');
        $metadata = [
            'admin_report_id' => $report->id,
            'admin_report_message_id' => $message->id,
            'subject' => $report->subject,
        ];

        if ($user->isAdmin()) {
            $this->sendNtfyPush(
                $title,
                $body,
                $actionUrl,
                self::RECEPTION_PRIORITY,
                $this->receptionTopic()
            );

            $this->broadcastSafely(new AdminReportMessagePosted($report, $user, false));

            return null;
        }

        $notification = $this->createAdminNotification(
            $user,
            'admin_report_replied',
            $title,
            $body,
            $actionUrl,
            $metadata,
            self::ADMIN_PRIORITY,
            $this->adminTopic()
        );

        $this->broadcastSafely(new AdminReportMessagePosted($report, $user, false));

        return $notification;
    }

    /**
     * Notify reception that a memo was posted to the board.
     */
    public function notifyReceptionMemoCreated(ReceptionMemo $memo, User $user): void
    {
        $title = __('New Reception Memo');
        $body = __(
            ':name posted: :title',
            [
                'name' => $user->name,
                'title' => $memo->title,
            ]
        );

        $this->sendNtfyPush(
            $title,
            $body,
            route('dashboard'),
            self::MEMO_PRIORITY,
            $this->receptionTopic()
        );

        $this->broadcastSafely(new ReceptionMemoPosted($memo, $user));
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
        array $metadata = [],
        int $priority = self::DEFAULT_PRIORITY,
        ?string $topic = null
    ): AdminNotification {
        $notification = AdminNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'actionable_url' => $actionableUrl,
            'metadata' => $metadata,
        ]);

        $this->sendNtfyPush($title, $message, $actionableUrl, $priority, $topic ?? $this->adminTopic());

        return $notification;
    }

    /**
     * Send a push notification via ntfy.sh.
     */
    private function sendNtfyPush(
        string $title,
        string $message,
        string $actionUrl,
        int $priority = self::DEFAULT_PRIORITY,
        ?string $topic = null
    ): void {
        $endpoint = $this->ntfyEndpoint($topic);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Title' => $title,
                    'Priority' => (string) $priority,
                    'Actions' => "view, Open, {$actionUrl}",
                ])
                ->withBody($message, 'text/plain')
                ->post($endpoint);

            if ($response->failed()) {
                Log::warning('ntfy.sh push notification failed to send.', [
                    'endpoint' => $endpoint,
                    'title' => $title,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send ntfy.sh push notification.', [
                'endpoint' => $endpoint,
                'title' => $title,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch a broadcast event without letting transport failures break the request.
     */
    private function broadcastSafely(object $event): void
    {
        $dispatch = function () use ($event): void {
            try {
                broadcast($event);
            } catch (\Throwable $e) {
                Log::error('Failed to broadcast realtime notification.', [
                    'event' => $event::class,
                    'exception' => $e->getMessage(),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }

    /**
     * Resolve the full ntfy endpoint for a topic.
     */
    private function ntfyEndpoint(?string $topic = null): string
    {
        $baseUrl = rtrim((string) config('services.ntfy.base_url', 'https://ntfy.sh'), '/');
        $resolvedTopic = $topic ?? $this->adminTopic();

        return "{$baseUrl}/{$resolvedTopic}";
    }

    /**
     * The admin ntfy topic.
     */
    private function adminTopic(): string
    {
        return (string) config('services.ntfy.admin_topic', 'mmc-hms');
    }

    /**
     * The reception ntfy topic.
     */
    private function receptionTopic(): string
    {
        return (string) config('services.ntfy.reception_topic', 'mmc-hms-reception');
    }
}
