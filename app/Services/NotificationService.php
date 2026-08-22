<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Events\AdminReportMessagePosted;
use App\Events\ReceptionMemoPosted;
use App\Models\AdminNotification;
use App\Models\AdminReport;
use App\Models\AdminReportMessage;
use App\Models\DutyAssignment;
use App\Models\EmployeeLeave;
use App\Models\EmployeeTodo;
use App\Models\KanbanItem;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireEntry;
use App\Models\NurseQuestionnaireResponse;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\QueueToken;
use App\Models\ReceptionMemo;
use App\Models\RoleRequest;
use App\Models\Shift;
use App\Models\SupervisorChecklistEntry;
use App\Models\SupervisorChecklistResponse;
use App\Models\User;
use App\Models\WardMaintenanceAnswer;
use App\Models\WardMaintenanceEntry;
use App\Models\WardMaintenanceFault;
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
     * Notify that a patient was registered or reserved without a contact phone.
     *
     * @param  'walk_in'|'reservation'|'lab'|'procedure'  $context
     * @param  array<string, mixed>  $metadata
     */
    public function notifyPatientWithoutPhone(
        User $user,
        Patient $patient,
        string $context,
        ?QueueToken $token = null,
        array $metadata = []
    ): AdminNotification {
        $routes = [
            'walk_in' => 'reception.walkin',
            'reservation' => 'reception.reservation',
            'lab' => 'reception.lab-entry',
            'procedure' => 'reception.procedures',
        ];

        $labels = [
            'walk_in' => __('walk-in'),
            'reservation' => __('reservation'),
            'lab' => __('lab entry'),
            'procedure' => __('procedure'),
        ];

        $title = __('📵 Patient Registered Without Contact Number');
        $message = $token !== null
            ? __(
                'Receptionist :name issued token :number for :patient (:mrn) without a contact number.',
                [
                    'name' => $user->name,
                    'number' => $token->token_number,
                    'patient' => $patient->name,
                    'mrn' => $patient->mrn ?? __('No MRN'),
                ]
            )
            : __(
                'Receptionist :name registered :patient (:mrn) for :context without a contact number.',
                [
                    'name' => $user->name,
                    'patient' => $patient->name,
                    'mrn' => $patient->mrn ?? __('No MRN'),
                    'context' => $labels[$context] ?? $context,
                ]
            );

        return $this->createAdminNotification(
            $user,
            'patient_without_phone',
            $title,
            $message,
            route($routes[$context] ?? 'reception.walkin'),
            array_merge([
                'context' => $context,
                'patient_id' => $patient->id,
                'patient_name' => $patient->name,
                'patient_mrn' => $patient->mrn,
                'token_id' => $token?->id,
                'token_number' => $token?->token_number,
                'queue_id' => $token?->service_queue_id,
            ], $metadata)
        );
    }

    /**
     * @deprecated Use notifyPatientWithoutPhone()
     */
    public function notifyReservationWithoutPhone(
        User $user,
        QueueToken $token,
        string $patientName,
        int $tokenNumber
    ): AdminNotification {
        $patient = $token->patient ?? Patient::query()->make(['name' => $patientName]);

        return $this->notifyPatientWithoutPhone($user, $patient, 'reservation', $token);
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
     * Notify admins that a receptionist has not submitted a checklist for a block.
     */
    public function notifySupervisorChecklistMissing(
        User $receptionist,
        CarbonInterface $blockStart,
        CarbonInterface $blockEnd
    ): ?AdminNotification {
        $alreadyNotified = AdminNotification::where('type', 'supervisor_checklist_missing')
            ->whereJsonContains('metadata', ['supervisor_id' => $receptionist->id])
            ->whereJsonContains('metadata', ['block_starts_at' => $blockStart->toDateTimeString()])
            ->exists();

        if ($alreadyNotified) {
            return null;
        }

        $title = __('⏰ Checklist Missing');
        $message = __(
            'Receptionist :name has not submitted the checklist for the :start - :end block.',
            [
                'name' => $receptionist->name,
                'start' => $blockStart->format('H:i'),
                'end' => $blockEnd->format('H:i'),
            ]
        );

        return $this->createAdminNotification(
            $receptionist,
            'supervisor_checklist_missing',
            $title,
            $message,
            route('admin.supervisor-checklist'),
            [
                'supervisor_id' => $receptionist->id,
                'block_starts_at' => $blockStart->toDateTimeString(),
                'block_ends_at' => $blockEnd->toDateTimeString(),
            ]
        );
    }

    /**
     * Notify admins that an admitted procedure is missing hourly vitals or FHR.
     */
    public function notifyProcedureVitalsMissing(
        Procedure $procedure,
        bool $missingVitals,
        bool $missingFhr,
        CarbonInterface $blockStart,
        CarbonInterface $blockEnd
    ): ?AdminNotification {
        $alreadyNotified = AdminNotification::where('type', 'procedure_vitals_missing')
            ->whereJsonContains('metadata', ['procedure_id' => $procedure->id])
            ->whereJsonContains('metadata', ['block_starts_at' => $blockStart->toDateTimeString()])
            ->exists();

        if ($alreadyNotified) {
            return null;
        }

        $missing = collect([
            $missingVitals ? __('vitals') : null,
            $missingFhr ? __('fetal heart') : null,
        ])->filter()->implode(' & ');

        $patientName = $procedure->patient?->name ?? __('Unknown');
        $room = $procedure->room?->number ?? $procedure->room_number ?? '-';

        $title = __('⏰ Procedure Readings Missing');
        $message = __(
            'Procedure #:id (:patient, room :room) is missing hourly :missing for :start - :end.',
            [
                'id' => $procedure->id,
                'patient' => $patientName,
                'room' => $room,
                'missing' => $missing,
                'start' => $blockStart->format('H:i'),
                'end' => $blockEnd->format('H:i'),
            ]
        );

        $actor = User::query()->where('role', UserRole::Admin)->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();

        if ($actor === null) {
            return null;
        }

        return $this->createAdminNotification(
            $actor,
            'procedure_vitals_missing',
            $title,
            $message,
            route('indoor.procedure', $procedure),
            [
                'procedure_id' => $procedure->id,
                'missing_vitals' => $missingVitals,
                'missing_fhr' => $missingFhr,
                'block_starts_at' => $blockStart->toDateTimeString(),
                'block_ends_at' => $blockEnd->toDateTimeString(),
            ]
        );
    }

    /**
     * Notify admins that a receptionist submitted a checklist with "No" answers.
     *
     * @param  Collection<int, SupervisorChecklistResponse>  $noResponses
     */
    public function notifySupervisorChecklistSubmitted(
        User $receptionist,
        SupervisorChecklistEntry $entry,
        Collection $noResponses
    ): AdminNotification {
        $title = __('⚠️ Checklist Submitted With No Answers');

        $items = $noResponses->map(function (SupervisorChecklistResponse $response): string {
            $text = $response->question->question_text;

            return $response->remarks
                ? "- {$text} ({$response->remarks})"
                : "- {$text}";
        })->implode("\n");

        $message = __(
            "Receptionist :name submitted the checklist for :start - :end with the following No answers:\n:items",
            [
                'name' => $receptionist->name,
                'start' => $entry->block_starts_at->format('H:i'),
                'end' => $entry->block_ends_at->format('H:i'),
                'items' => $items,
            ]
        );

        return $this->createAdminNotification(
            $receptionist,
            'supervisor_checklist_no_answers',
            $title,
            $message,
            route('admin.supervisor-checklist'),
            [
                'supervisor_id' => $receptionist->id,
                'entry_id' => $entry->id,
                'block_starts_at' => $entry->block_starts_at->toDateTimeString(),
                'block_ends_at' => $entry->block_ends_at->toDateTimeString(),
                'no_response_ids' => $noResponses->pluck('id')->all(),
            ]
        );
    }

    /**
     * Notify admins that an incharge nurse missed a questionnaire block.
     */
    public function notifyNurseQuestionnaireMissing(
        User $nurse,
        NurseQuestionnaire $questionnaire,
        CarbonInterface $blockStart,
        CarbonInterface $blockEnd
    ): ?AdminNotification {
        $alreadyNotified = AdminNotification::where('type', 'nurse_questionnaire_missing')
            ->whereJsonContains('metadata', ['nurse_id' => $nurse->id])
            ->whereJsonContains('metadata', ['questionnaire_id' => $questionnaire->id])
            ->whereJsonContains('metadata', ['block_starts_at' => $blockStart->toDateTimeString()])
            ->exists();

        if ($alreadyNotified) {
            return null;
        }

        $title = __('⏰ Nurse Questionnaire Missing');
        $message = __(
            'Incharge nurse :name has not submitted :form for the :start - :end block.',
            [
                'name' => $nurse->name,
                'form' => $questionnaire->name,
                'start' => $blockStart->format('H:i'),
                'end' => $blockEnd->format('H:i'),
            ]
        );

        return $this->createAdminNotification(
            $nurse,
            'nurse_questionnaire_missing',
            $title,
            $message,
            route('admin.nurse-questionnaire-submissions'),
            [
                'nurse_id' => $nurse->id,
                'questionnaire_id' => $questionnaire->id,
                'block_starts_at' => $blockStart->toDateTimeString(),
                'block_ends_at' => $blockEnd->toDateTimeString(),
            ]
        );
    }

    /**
     * Notify admins that an incharge nurse submitted a questionnaire with No answers.
     *
     * @param  Collection<int, NurseQuestionnaireResponse>  $noResponses
     */
    public function notifyNurseQuestionnaireSubmitted(
        User $nurse,
        NurseQuestionnaireEntry $entry,
        Collection $noResponses
    ): AdminNotification {
        $title = __('⚠️ Nurse Questionnaire Submitted With No Answers');

        $items = $noResponses->map(function (NurseQuestionnaireResponse $response): string {
            $text = $response->question->question_text;

            return $response->remarks
                ? "- {$text} ({$response->remarks})"
                : "- {$text}";
        })->implode("\n");

        $message = __(
            "Incharge nurse :name submitted :form for :start - :end with the following No answers:\n:items",
            [
                'name' => $nurse->name,
                'form' => $entry->questionnaire->name,
                'start' => $entry->block_starts_at->format('H:i'),
                'end' => $entry->block_ends_at->format('H:i'),
                'items' => $items,
            ]
        );

        return $this->createAdminNotification(
            $nurse,
            'nurse_questionnaire_no_answers',
            $title,
            $message,
            route('admin.nurse-questionnaire-submissions'),
            [
                'nurse_id' => $nurse->id,
                'questionnaire_id' => $entry->questionnaire_id,
                'entry_id' => $entry->id,
                'block_starts_at' => $entry->block_starts_at->toDateTimeString(),
                'block_ends_at' => $entry->block_ends_at->toDateTimeString(),
                'no_response_ids' => $noResponses->pluck('id')->all(),
            ]
        );
    }

    /**
     * Notify admins that a ward maintenance checklist was submitted with faults.
     */
    public function notifyWardMaintenanceFaults(User $nurse, WardMaintenanceEntry $entry): AdminNotification
    {
        $definition = app(WardMaintenanceChecklistDefinition::class);
        $labels = array_merge(
            $definition->sectionAItems(),
            $definition->sectionBItems(),
            $definition->sectionCGyneItems(),
            $definition->sectionCPrivateItems(),
            $definition->sectionDGyneItems(),
            $definition->sectionDPrivateItems(),
            $definition->sectionEItems(),
            $definition->sectionFItems(),
            $definition->sectionGItems(),
        );

        $faultAnswers = $entry->answers
            ->filter(fn (WardMaintenanceAnswer $answer) => $answer->isFault())
            ->take(12)
            ->map(function (WardMaintenanceAnswer $answer) use ($labels): string {
                $label = $labels[$answer->item_key] ?? $answer->item_key;
                $location = $answer->location_key !== '' ? " ({$answer->location_key})" : '';

                if ($answer->section === 'E') {
                    $parts = [];

                    if ($answer->available === false) {
                        $parts[] = __('not available');
                    }

                    if ($answer->functional === false) {
                        $parts[] = __('not functional');
                    }

                    return '- '.$label.$location.' ('.implode(', ', $parts).')';
                }

                return '- '.$label.$location;
            })
            ->values()
            ->all();

        $faultRows = $entry->faults
            ->take(5)
            ->map(function (WardMaintenanceFault $fault): string {
                $priority = $fault->priority?->label() ?? __('Unspecified');

                return '- '.($fault->description ?: __('Fault logged'))." [{$priority}]";
            })
            ->values()
            ->all();

        $items = collect($faultAnswers)->merge($faultRows)->implode("\n");

        if ($entry->patient_safety_fault === 'yes') {
            $items = __('Patient safety/care fault reported.')."\n{$items}";
        }

        $title = __('⚠️ Ward Maintenance Faults Reported');
        $message = __(
            "Incharge nurse :name submitted the :shift ward maintenance checklist for :date with faults:\n:items",
            [
                'name' => $nurse->name,
                'shift' => $entry->shift->label(),
                'date' => $entry->checklist_date->format('Y-m-d'),
                'items' => trim($items) !== '' ? $items : __('See submission for details.'),
            ]
        );

        return $this->createAdminNotification(
            $nurse,
            'ward_maintenance_faults',
            $title,
            $message,
            route('admin.ward-maintenance-submissions', [
                'date' => $entry->checklist_date->format('Y-m-d'),
                'shift' => $entry->shift->value,
            ]),
            [
                'nurse_id' => $nurse->id,
                'entry_id' => $entry->id,
                'checklist_date' => $entry->checklist_date->format('Y-m-d'),
                'shift' => $entry->shift->value,
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
     * Notify admins about a missing check-in punch for a started duty.
     */
    public function notifyAttendanceMissingPunch(DutyAssignment $assignment): bool
    {
        $alreadyNotified = AdminNotification::query()
            ->where('type', 'attendance_missing_punch')
            ->whereJsonContains('metadata', ['duty_assignment_id' => $assignment->id])
            ->exists();

        if ($alreadyNotified) {
            return false;
        }

        $systemUser = User::query()->where('role', UserRole::Admin)->first();

        if ($systemUser === null) {
            return false;
        }

        $this->createAdminNotification(
            $systemUser,
            'attendance_missing_punch',
            __('Missing Check-In'),
            __(':name has not checked in for duty starting at :time.', [
                'name' => $assignment->healthAide->name,
                'time' => $assignment->starts_at->format('M j, H:i'),
            ]),
            route('admin.attendance.daily', ['date' => $assignment->date->toDateString()]),
            [
                'duty_assignment_id' => $assignment->id,
                'health_aide_id' => $assignment->health_aide_id,
            ],
        );

        return true;
    }

    /**
     * Notify admins when device sync fails repeatedly.
     */
    public function notifyAttendanceSyncFailed(string $deviceName, string $error): void
    {
        $systemUser = User::query()->where('role', UserRole::Admin)->first();

        if ($systemUser === null) {
            return;
        }

        $this->createAdminNotification(
            $systemUser,
            'attendance_sync_failed',
            __('Attendance Sync Failed'),
            __('Device :device failed to sync: :error', [
                'device' => $deviceName,
                'error' => $error,
            ]),
            route('admin.attendance.device'),
            ['device_name' => $deviceName],
        );
    }

    /**
     * Notify admins about yesterday's attendance issues.
     */
    public function notifyAttendanceDailySummary(string $date, int $absences, int $lates, int $incomplete): void
    {
        $systemUser = User::query()->where('role', UserRole::Admin)->first();

        if ($systemUser === null) {
            return;
        }

        $this->createAdminNotification(
            $systemUser,
            'attendance_daily_summary',
            __('Attendance Summary'),
            __(':date — :absences absent, :lates late, :incomplete incomplete.', [
                'date' => $date,
                'absences' => $absences,
                'lates' => $lates,
                'incomplete' => $incomplete,
            ]),
            route('admin.attendance.daily', ['date' => $date]),
            compact('date', 'absences', 'lates', 'incomplete'),
        );
    }

    /**
     * Notify management that an emergency shift was assigned.
     */
    public function notifyEmergencyShiftAssigned(DutyAssignment $assignment, User $user): AdminNotification
    {
        return $this->createAdminNotification(
            $user,
            'attendance_emergency_shift',
            __('Emergency Shift Assigned'),
            __(':name assigned to emergency duty from :start to :end.', [
                'name' => $assignment->healthAide->name,
                'start' => $assignment->starts_at->format('M j, H:i'),
                'end' => $assignment->ends_at->format('M j, H:i'),
            ]),
            route('admin.attendance.roster'),
            ['duty_assignment_id' => $assignment->id],
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
