<?php

use App\Actions\DiscardProcedurePayment;
use App\Enums\PaymentMode;
use App\Enums\ProcedureStatus;
use App\Livewire\Concerns\InteractsWithPatientIntake;
use App\Models\Doctor;
use App\Models\Procedure;
use App\Models\ProcedurePayment;
use App\Models\ProcedureType;
use App\Models\Room;
use App\Models\Shift;
use App\Services\PatientIntakeService;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Procedures')] class extends Component
{
    use InteractsWithPatientIntake {
        selectMatchedPatient as selectMatchedPatientFromIntake;
        addNewFamilyMember as addNewFamilyMemberFromIntake;
        clearSelectedPatient as clearSelectedPatientFromIntake;
    }
    use WithPagination;

    public string $search = '';

    /** @var int Number of days to include; 0 means all procedures. */
    public int $days = 3;

    public bool $showProcedureModal = false;

    public bool $showPaymentModal = false;

    public bool $showViewModal = false;

    public bool $showAdmissionModal = false;

    public bool $showPaymentLedger = false;

    public ?int $editingProcedureId = null;

    public ?int $viewingProcedureId = null;

    public ?int $admittingProcedureId = null;

    #[Validate]
    public string $admissionCnic = '';

    #[Validate]
    public ?int $admissionRoomId = null;

    #[Validate]
    public string $patientName = '';

    #[Validate]
    public string $husbandName = '';

    #[Validate]
    public ?int $patientAge = null;

    #[Validate]
    public ?int $procedureTypeId = null;

    #[Validate]
    public ?string $expectedDeliveryDate = null;

    #[Validate]
    public string $fullAmount = '';

    #[Validate]
    public ?int $doctorId = null;

    public bool $hasAdvancePayment = false;

    #[Validate]
    public string $advancePayment = '';

    #[Validate]
    public string $advancePaymentMode = 'cash';

    #[Validate]
    public string $paymentAmount = '';

    #[Validate]
    public string $paymentMode = 'cash';

    public bool $excludeFromCurrentShift = false;

    public ?int $selectedPreviousShiftId = null;

    /**
     * Get the validation rules for the procedure form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'patientName' => ['required', 'string', 'max:255'],
            ...$this->patientIntakePhoneRules(),
            'husbandName' => ['required', 'string', 'max:255'],
            'patientAge' => ['required', 'integer', 'min:0', 'max:150'],
            'procedureTypeId' => ['required', 'integer', 'exists:procedure_types,id'],
            'expectedDeliveryDate' => ['required', 'date'],
            'fullAmount' => ['required', 'numeric', 'min:0'],
            'doctorId' => ['nullable', 'integer', 'exists:doctors,id'],
            'hasAdvancePayment' => ['boolean'],
            'advancePayment' => [
                'exclude_unless:hasAdvancePayment,true',
                'required',
                'numeric',
                'min:0.01',
                function ($attribute, $value, $fail) {
                    if ((float) $value > (float) $this->fullAmount) {
                        $fail(__('Advance payment cannot exceed the full amount.'));
                    }
                },
            ],
            'advancePaymentMode' => [
                'exclude_unless:hasAdvancePayment,true',
                'required',
                'string',
                'in:'.implode(',', PaymentMode::values()),
            ],
            'paymentAmount' => ['required', 'numeric', 'min:0'],
            'paymentMode' => ['required', 'string', 'in:'.implode(',', PaymentMode::values())],
            'excludeFromCurrentShift' => ['boolean'],
            'selectedPreviousShiftId' => [
                'exclude_unless:excludeFromCurrentShift,true',
                'required',
                'integer',
                Rule::exists('shifts', 'id')->where('status', 'closed'),
            ],
            'admissionCnic' => ['required', 'string', 'max:20'],
            'admissionRoomId' => [
                'required',
                'integer',
                Rule::exists('rooms', 'id')->where('is_active', true),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $room = Room::query()->find($value);

                    if ($room === null) {
                        return;
                    }

                    $occupying = $room->currentAdmission;

                    if ($occupying !== null && $occupying->id !== $this->admittingProcedureId) {
                        $fail(__('This room is already occupied.'));
                    }
                },
            ],
        ];
    }

    /**
     * Reset pagination when the search term changes.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when the day window changes.
     */
    public function updatingDays(): void
    {
        $this->resetPage();
    }

    /**
     * Set how many recent days of procedures to show (0 = all).
     */
    public function setDays(int $days): void
    {
        if (! in_array($days, [3, 7, 14, 30, 0], true)) {
            return;
        }

        $this->days = $days;
        $this->resetPage();
    }

    /**
     * Open the modal to create a new procedure.
     */
    public function create(): void
    {
        $this->resetProcedureForm();
        $this->editingProcedureId = null;
        $this->showProcedureModal = true;
    }

    /**
     * Open the modal to edit an existing procedure.
     */
    public function edit(int $id): void
    {
        $this->resetProcedureForm();
        $this->editingProcedureId = $id;
        $this->showViewModal = false;

        $procedure = Procedure::with('patient.family')->findOrFail($id);

        $this->selectedPatientId = $procedure->patient->id;
        $this->patientName = $procedure->patient->name;
        $this->patientPhone = $procedure->patient->contactPhone() ?? '';
        $this->hasNoPhone = blank($this->patientPhone);
        $this->husbandName = $procedure->patient->husband_name ?? '';
        $this->patientAge = $procedure->patient->age;
        $this->procedureTypeId = $procedure->procedure_type_id
            ?? ProcedureType::query()->where('name', $procedure->name)->value('id');
        $this->expectedDeliveryDate = $procedure->expected_delivery_date?->format('Y-m-d');
        $this->fullAmount = (string) $procedure->full_amount;
        $this->doctorId = $procedure->doctor_id;
        $this->hasAdvancePayment = false;
        $this->advancePayment = '';

        $this->showProcedureModal = true;
    }

    /**
     * Open the modal to add a payment to a procedure.
     */
    public function addPayment(int $id): void
    {
        $this->resetPaymentForm();
        $this->editingProcedureId = $id;
        $this->showViewModal = false;
        $this->showPaymentModal = true;
    }

    /**
     * Open the detail/ledger modal for the selected procedure.
     */
    public function viewProcedure(int $id): void
    {
        $this->showPaymentLedger = false;
        $this->viewingProcedureId = $id;
        $this->showViewModal = true;
    }

    /**
     * Toggle the payment ledger section in the detail modal.
     */
    public function togglePaymentLedger(): void
    {
        $this->showPaymentLedger = ! $this->showPaymentLedger;
    }

    /**
     * Open the admission modal for the selected procedure.
     */
    public function addAdmission(int $id): void
    {
        $procedure = Procedure::with('patient')->findOrFail($id);

        $this->resetAdmissionForm();
        $this->admittingProcedureId = $id;
        $this->admissionCnic = $procedure->patient->cnic ?? '';
        $this->admissionRoomId = $procedure->room_id
            ?? Room::query()->where('number', $procedure->room_number)->value('id');
        $this->showViewModal = false;
        $this->showAdmissionModal = true;
    }

    /**
     * Reset the admission form fields.
     */
    private function resetAdmissionForm(): void
    {
        $this->reset(['admissionCnic', 'admissionRoomId']);
        $this->resetErrorBag();
    }

    /**
     * Close the admission modal.
     */
    public function closeAdmissionModal(): void
    {
        $this->showAdmissionModal = false;
        $this->admittingProcedureId = null;
        $this->resetAdmissionForm();
    }

    /**
     * Admit the patient for the selected procedure.
     */
    public function admitPatient(): void
    {
        $validated = $this->validate([
            'admissionCnic' => $this->rules()['admissionCnic'],
            'admissionRoomId' => $this->rules()['admissionRoomId'],
        ]);

        $procedure = Procedure::with('patient')->findOrFail($this->admittingProcedureId);
        $room = Room::active()->findOrFail($validated['admissionRoomId']);

        DB::transaction(function () use ($procedure, $room, $validated) {
            $procedure->patient->update([
                'cnic' => $validated['admissionCnic'],
            ]);

            $procedure->update([
                'room_id' => $room->id,
                'room_number' => $room->number,
                'admitted_at' => $procedure->admitted_at ?? now(),
                'status' => ProcedureStatus::Admitted,
            ]);
        });

        $this->closeAdmissionModal();

        Flux::toast(variant: 'success', text: __('Patient admitted.'));
    }

    /**
     * Close the detail modal and reset its state.
     */
    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewingProcedureId = null;
        $this->showPaymentLedger = false;
    }

    /**
     * Reset the procedure form fields.
     */
    private function resetProcedureForm(): void
    {
        $this->reset([
            'patientName',
            ...$this->patientIntakeResetFields(),
            'husbandName',
            'patientAge',
            'procedureTypeId',
            'expectedDeliveryDate',
            'fullAmount',
            'doctorId',
            'hasAdvancePayment',
            'advancePayment',
        ]);
        $this->advancePaymentMode = PaymentMode::Cash->value;
        $this->resetErrorBag();
    }

    /**
     * Reset the payment form fields.
     */
    private function resetPaymentForm(): void
    {
        $this->reset(['paymentAmount', 'excludeFromCurrentShift', 'selectedPreviousShiftId']);
        $this->paymentMode = PaymentMode::Cash->value;
        $this->resetErrorBag();
    }

    /**
     * Clear the previous-shift selection when the admin unchecks the option.
     */
    public function updatedExcludeFromCurrentShift(bool $value): void
    {
        if (! $value) {
            $this->selectedPreviousShiftId = null;
            $this->resetErrorBag('selectedPreviousShiftId');
        }
    }

    /**
     * Select a previous closed shift for the payment.
     */
    public function selectPreviousShift(int $shiftId): void
    {
        if (! auth()->user()?->isAdmin() || ! $this->excludeFromCurrentShift) {
            return;
        }

        $shift = Shift::query()
            ->where('status', 'closed')
            ->find($shiftId);

        if ($shift === null) {
            return;
        }

        $this->selectedPreviousShiftId = $shift->id;
        $this->resetErrorBag('selectedPreviousShiftId');
    }

    /**
     * Close the procedure modal.
     */
    public function closeProcedureModal(): void
    {
        $this->showProcedureModal = false;
        $this->editingProcedureId = null;
        $this->resetProcedureForm();
    }

    /**
     * Close the payment modal.
     */
    public function closePaymentModal(): void
    {
        $this->showPaymentModal = false;
        $this->editingProcedureId = null;
        $this->resetPaymentForm();
    }

    /**
     * Get the procedure currently being viewed with its ledger.
     */
    #[Computed]
    public function viewedProcedure(): ?Procedure
    {
        if ($this->viewingProcedureId === null) {
            return null;
        }

        return Procedure::with(['patient.family', 'doctor', 'payments.creator', 'payments.shift', 'shift', 'procedureType.documents'])
            ->withSum('activePayments as payments_sum_amount', 'amount')
            ->find($this->viewingProcedureId);
    }

    /**
     * Get recent closed shifts for admin backfill selection.
     *
     * @return Collection<int, Shift>
     */
    #[Computed]
    public function previousShifts(): Collection
    {
        return Shift::query()
            ->with('user')
            ->where('status', 'closed')
            ->latest('closed_at')
            ->limit(20)
            ->get();
    }

    /**
     * Select an existing patient, blocking reassignment while editing a procedure.
     */
    public function selectMatchedPatient(int $patientId): void
    {
        if ($this->editingProcedureId !== null) {
            $linkedPatientId = Procedure::query()->whereKey($this->editingProcedureId)->value('patient_id');

            if ($linkedPatientId !== null && $patientId !== (int) $linkedPatientId) {
                $this->rejectPatientChange();

                return;
            }
        }

        $this->selectMatchedPatientFromIntake($patientId);
    }

    /**
     * Block creating a different patient while editing a procedure.
     */
    public function addNewFamilyMember(): void
    {
        if ($this->editingProcedureId !== null) {
            $this->rejectPatientChange();

            return;
        }

        $this->addNewFamilyMemberFromIntake();
    }

    /**
     * Keep the linked patient selected while editing a procedure.
     */
    public function clearSelectedPatient(): void
    {
        if ($this->editingProcedureId !== null) {
            $this->rejectPatientChange();

            return;
        }

        $this->clearSelectedPatientFromIntake();
    }

    /**
     * Persist a new or updated procedure.
     */
    public function saveProcedure(): void
    {
        $rules = [
            'patientName' => $this->rules()['patientName'],
            'patientPhone' => $this->rules()['patientPhone'],
            'hasNoPhone' => $this->rules()['hasNoPhone'],
            'selectedPatientId' => $this->rules()['selectedPatientId'],
            'husbandName' => $this->rules()['husbandName'],
            'patientAge' => $this->rules()['patientAge'],
            'procedureTypeId' => $this->rules()['procedureTypeId'],
            'expectedDeliveryDate' => $this->rules()['expectedDeliveryDate'],
            'fullAmount' => $this->rules()['fullAmount'],
            'doctorId' => $this->rules()['doctorId'],
        ];

        if ($this->editingProcedureId === null) {
            $rules['hasAdvancePayment'] = $this->rules()['hasAdvancePayment'];
            $rules['advancePayment'] = $this->rules()['advancePayment'];
            $rules['advancePaymentMode'] = $this->rules()['advancePaymentMode'];
        }

        $validated = $this->validate($rules);

        $shift = Shift::current();

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('Please open a shift first.'));

            return;
        }

        if ($this->editingProcedureId !== null) {
            if (! $this->updateProcedure($validated)) {
                return;
            }
        } else {
            $this->storeProcedure($validated, $shift);
        }

        $this->closeProcedureModal();
    }

    /**
     * Store a new procedure with patient and optional advance payment.
     *
     * @param  array<string, mixed>  $validated
     */
    private function storeProcedure(array $validated, Shift $shift): void
    {
        DB::transaction(function () use ($validated, $shift) {
            $procedureType = ProcedureType::findOrFail($validated['procedureTypeId']);

            $patient = $this->resolveIntakePatient([
                'name' => $validated['patientName'],
                'husband_name' => $validated['husbandName'],
                'age' => $validated['patientAge'],
                'gender' => 'female',
            ]);

            if ($this->hasNoPhone && auth()->user() !== null) {
                app(PatientIntakeService::class)->notifyWithoutPhone(
                    auth()->user(),
                    $patient,
                    'procedure',
                );
            }

            $procedure = Procedure::create([
                'patient_id' => $patient->id,
                'procedure_type_id' => $procedureType->id,
                'name' => $procedureType->name,
                'expected_delivery_date' => $validated['expectedDeliveryDate'],
                'full_amount' => $validated['fullAmount'],
                'doctor_id' => $validated['doctorId'] ?: null,
                'created_by' => auth()->id(),
                'shift_id' => $shift->id,
                'status' => ProcedureStatus::Booking,
            ]);

            if ($this->hasAdvancePayment && (float) ($validated['advancePayment'] ?? 0) > 0) {
                ProcedurePayment::create([
                    'procedure_id' => $procedure->id,
                    'amount' => $validated['advancePayment'],
                    'mode' => $validated['advancePaymentMode'],
                    'created_by' => auth()->id(),
                    'shift_id' => $shift->id,
                ]);
            }
        });

        Flux::toast(variant: 'success', text: __('Procedure created.'));
    }

    /**
     * Update an existing procedure.
     *
     * @param  array<string, mixed>  $validated
     */
    private function updateProcedure(array $validated): bool
    {
        $procedure = Procedure::with('payments')->findOrFail($this->editingProcedureId);

        if ($this->selectedPatientId !== null && $this->selectedPatientId !== $procedure->patient_id) {
            $this->rejectPatientChange();

            return false;
        }

        if ((float) $validated['fullAmount'] < $procedure->totalPaid()) {
            $message = __('Full amount cannot be less than the total paid.');
            $this->addError('fullAmount', $message);
            Flux::toast(variant: 'danger', text: $message);

            return false;
        }

        DB::transaction(function () use ($procedure, $validated) {
            $procedureType = ProcedureType::findOrFail($validated['procedureTypeId']);

            $procedure->patient->update([
                'name' => $validated['patientName'],
                'husband_name' => $validated['husbandName'],
                'age' => $validated['patientAge'],
            ]);

            app(PatientIntakeService::class)->updateContactPhone(
                $procedure->patient,
                $this->hasNoPhone ? null : $validated['patientPhone'],
            );

            $procedure->update([
                'procedure_type_id' => $procedureType->id,
                'name' => $procedureType->name,
                'expected_delivery_date' => $validated['expectedDeliveryDate'],
                'full_amount' => $validated['fullAmount'],
                'doctor_id' => $validated['doctorId'] ?: null,
            ]);
        });

        Flux::toast(variant: 'success', text: __('Procedure updated.'));

        return true;
    }

    /**
     * Tell the user why the procedure patient cannot be changed.
     */
    private function rejectPatientChange(): void
    {
        $message = __('The patient on an existing procedure cannot be changed. Update this patient\'s details, or create a new procedure for a different patient.');

        $this->addError('selectedPatientId', $message);
        Flux::toast(variant: 'danger', text: $message);
    }

    /**
     * Mark the procedure as fully paid with a balance payment not tied to any shift.
     */
    public function markPaid(int $id): void
    {
        $procedure = Procedure::with('payments')->findOrFail($id);
        $balance = $procedure->balance();

        if ($balance <= 0) {
            Flux::toast(variant: 'danger', text: __('This procedure is already fully paid.'));

            return;
        }

        ProcedurePayment::create([
            'procedure_id' => $procedure->id,
            'amount' => $balance,
            'mode' => PaymentMode::Cash,
            'created_by' => auth()->id(),
            'shift_id' => null,
        ]);

        $this->viewingProcedureId = $id;
        $this->showPaymentLedger = true;
        unset($this->viewedProcedure);

        Flux::toast(variant: 'success', text: __('Procedure marked as paid.'));
    }

    /**
     * Delete a procedure and its related payments.
     */
    public function deleteProcedure(int $id): void
    {
        $user = auth()->user();

        if ($user === null || ! $user->isAdmin()) {
            abort(403);
        }

        $procedure = Procedure::findOrFail($id);
        $procedure->delete();

        if ($this->viewingProcedureId === $id) {
            $this->closeViewModal();
        }

        unset($this->procedures);

        Flux::toast(variant: 'success', text: __('Procedure deleted.'));
    }

    /**
     * Store a new payment for the selected procedure.
     */
    public function savePayment(): void
    {
        $user = auth()->user();
        $canAssignPreviousShift = $user?->isAdmin() === true;

        if (! $canAssignPreviousShift) {
            $this->excludeFromCurrentShift = false;
            $this->selectedPreviousShiftId = null;
        }

        $rules = [
            'paymentAmount' => $this->rules()['paymentAmount'],
            'paymentMode' => $this->rules()['paymentMode'],
        ];

        if ($canAssignPreviousShift) {
            $rules['excludeFromCurrentShift'] = $this->rules()['excludeFromCurrentShift'];
            $rules['selectedPreviousShiftId'] = $this->rules()['selectedPreviousShiftId'];
        }

        $validated = $this->validate($rules);

        $shift = null;

        if ($canAssignPreviousShift && $this->excludeFromCurrentShift) {
            $shift = Shift::query()
                ->where('status', 'closed')
                ->find($validated['selectedPreviousShiftId'] ?? $this->selectedPreviousShiftId);

            if ($shift === null) {
                Flux::toast(variant: 'danger', text: __('Please select a previous shift.'));

                return;
            }
        } else {
            $shift = Shift::current();

            if ($shift === null) {
                Flux::toast(variant: 'danger', text: __('Please open a shift first.'));

                return;
            }
        }

        $procedure = Procedure::with('payments')->findOrFail($this->editingProcedureId);

        if ((float) $validated['paymentAmount'] > $procedure->balance()) {
            Flux::toast(variant: 'danger', text: __('Payment amount cannot exceed the remaining balance.'));

            return;
        }

        ProcedurePayment::create([
            'procedure_id' => $procedure->id,
            'amount' => $validated['paymentAmount'],
            'mode' => $validated['paymentMode'],
            'created_by' => auth()->id(),
            'shift_id' => $shift->id,
        ]);

        $this->closePaymentModal();

        Flux::toast(variant: 'success', text: __('Payment added.'));
    }

    /**
     * Discard a procedure payment so it no longer counts as collected.
     */
    public function discardPayment(int $paymentId): void
    {
        $user = auth()->user();

        if ($user === null || ! $user->isAdmin()) {
            abort(403);
        }

        $payment = ProcedurePayment::query()->findOrFail($paymentId);

        try {
            app(DiscardProcedurePayment::class)->handle($user, $payment);
        } catch (\InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->showPaymentLedger = true;
        unset($this->viewedProcedure, $this->procedures);

        Flux::toast(variant: 'success', text: __('Payment discarded.'));
    }

    /**
     * Get the currently open shift for the user.
     */
    #[Computed]
    public function currentShift(): ?Shift
    {
        return Shift::current();
    }

    /**
     * Get the list of procedures filtered by day window and patient name or MRN.
     * Admitted (on-ward) patients always appear first and remain visible regardless of the day window.
     */
    #[Computed]
    public function procedures(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Procedure::query()
            ->with(['patient', 'doctor'])
            ->withSum('activePayments as payments_sum_amount', 'amount')
            ->when($search !== '', function (Builder $query) use ($search) {
                $term = '%'.$search.'%';

                $query->whereHas('patient', function (Builder $patientQuery) use ($term) {
                    $patientQuery->where('name', 'like', $term)
                        ->orWhere('mrn', 'like', $term);
                });
            })
            ->when($search === '' && $this->days > 0, function (Builder $query) {
                $since = now()->subDays($this->days)->startOfDay();

                $query->where(function (Builder $window) use ($since) {
                    $window->where('created_at', '>=', $since)
                        ->orWhere(function (Builder $onWard) {
                            $onWard->whereNotNull('admitted_at')->whereNull('discharged_at');
                        });
                });
            })
            ->orderByRaw('case when admitted_at is not null and discharged_at is null then 0 else 1 end')
            ->latest()
            ->paginate($this->days > 0 && $search === '' ? 100 : 24);
    }

    /**
     * Get the list of active procedure types.
     *
     * @return Collection<int, ProcedureType>
     */
    #[Computed]
    public function procedureTypes(): Collection
    {
        return ProcedureType::active()->orderBy('name')->get();
    }

    /**
     * Get the list of active rooms with occupancy status.
     *
     * @return Collection<int, Room>
     */
    #[Computed]
    public function rooms(): Collection
    {
        return Room::active()
            ->with('currentAdmission')
            ->orderBy('number')
            ->get();
    }

    /**
     * Get the list of doctors.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function doctors(): Collection
    {
        return Doctor::active()->orderBy('name')->get();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <flux:heading level="1">{{ __('Procedures') }}</flux:heading>

        @if (! $this->currentShift)
            <flux:card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('No Open Shift') }}</flux:heading>
                        <flux:text class="text-zinc-500">{{ __('Open a shift to create procedures and record payments.') }}</flux:text>
                    </div>
                    <flux:button variant="primary" icon="lock-open" :href="route('reception.shift')" wire:navigate>
                        {{ __('Open Shift') }}
                    </flux:button>
                </div>
            </flux:card>
        @endif

        <flux:card>
            <div class="flex flex-col gap-4">
                <flux:field>
                    <flux:label>{{ __('Search by name or MR number') }}</flux:label>
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        type="search"
                        placeholder="{{ __('Patient name or MRN...') }}"
                        icon="magnifying-glass"
                    />
                </flux:field>

                @if (trim($search) === '')
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <flux:text class="text-sm text-zinc-500">
                            {{ $days > 0
                                ? __('Showing procedures from the last :days days (admitted patients always included).', ['days' => $days])
                                : __('Showing all procedures.') }}
                        </flux:text>
                        <div class="flex flex-wrap gap-2">
                            @foreach ([3 => __('3 days'), 7 => __('7 days'), 14 => __('14 days'), 30 => __('30 days'), 0 => __('All')] as $value => $label)
                                <flux:button
                                    type="button"
                                    size="sm"
                                    variant="{{ $days === $value ? 'primary' : 'ghost' }}"
                                    wire:click="setDays({{ $value }})"
                                >
                                    {{ $label }}
                                </flux:button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </flux:card>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <button
                type="button"
                wire:click="create"
                class="flex min-h-48 cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-5 text-zinc-500 transition hover:border-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
            >
                <flux:icon name="plus" class="size-10" />
                <flux:text class="font-medium">{{ __('Add new procedure') }}</flux:text>
            </button>

            @forelse ($this->procedures as $procedure)
                @php
                    $paid = (float) ($procedure->payments_sum_amount ?? 0);
                    $balance = $procedure->full_amount - $paid;
                    $isPaid = $balance <= 0;
                @endphp
                <div
                    wire:key="procedure-{{ $procedure->id }}"
                    wire:click="viewProcedure({{ $procedure->id }})"
                    class="group flex cursor-pointer flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <flux:heading level="3" class="truncate text-base font-semibold">
                                {{ $procedure->patient->name }}
                            </flux:heading>
                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $procedure->patient->mrn ?? __('No MRN') }}
                            </flux:text>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            @if ($isPaid)
                                <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                            @endif
                            <flux:badge size="sm" color="{{ $procedure->status->badgeColor() }}">
                                {{ $procedure->status->label() }}
                            </flux:badge>
                        </div>
                    </div>

                    <div class="space-y-1 text-sm">
                        <flux:text class="font-medium">{{ $procedure->name }}</flux:text>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Doctor') }}: {{ $procedure->doctor?->name ?? '-' }}
                        </flux:text>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ __('EDD') }}:
                            {{ $procedure->expected_delivery_date?->format('M j, Y') ?? '-' }}
                        </flux:text>
                        @if ($procedure->isAdmitted())
                            <flux:text class="text-zinc-500 dark:text-zinc-400">
                                {{ __('Room') }}: {{ $procedure->room_number ?? '-' }}
                            </flux:text>
                        @endif
                    </div>

                    <div class="mt-auto grid grid-cols-3 gap-2 border-t border-zinc-100 pt-3 text-xs dark:border-zinc-700">
                        <div>
                            <flux:text class="text-zinc-400">{{ __('Package') }}</flux:text>
                            <flux:text class="font-medium">{{ number_format($procedure->full_amount, 2) }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-zinc-400">{{ __('Paid') }}</flux:text>
                            <flux:text class="font-medium">{{ number_format($paid, 2) }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-zinc-400">{{ __('Balance') }}</flux:text>
                            <flux:text class="font-medium">{{ number_format($balance, 2) }}</flux:text>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 py-16 md:col-span-1 xl:col-span-2 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:icon name="clipboard-document-list" class="size-10 text-zinc-400" />
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ filled($search) ? __('No procedures match your search.') : __('No procedures yet. Use the + card to add one.') }}
                    </flux:text>
                </div>
            @endforelse
        </div>

        <div class="mt-2">
            {{ $this->procedures->links() }}
        </div>
    </div>

    <flux:modal wire:model="showProcedureModal" class="w-full max-w-2xl">
        <flux:heading level="2">
            {{ $editingProcedureId ? __('Edit Procedure') : __('Add New Procedure') }}
        </flux:heading>

        <form wire:submit="saveProcedure" class="mt-6 space-y-6">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    @include('partials.reception.patient-intake')
                </div>

                @if ($this->shouldShowPatientNameField())
                    <flux:field class="sm:col-span-2">
                        <flux:label>{{ __('Name') }}</flux:label>
                        <flux:input wire:model="patientName" type="text" required />
                        <flux:error name="patientName" />
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>{{ __('Husband') }}</flux:label>
                    <flux:input wire:model="husbandName" type="text" required />
                    <flux:error name="husbandName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Age') }}</flux:label>
                    <flux:input wire:model="patientAge" type="number" min="0" max="150" required />
                    <flux:error name="patientAge" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Procedure name') }}</flux:label>
                    <flux:select wire:model="procedureTypeId" required>
                        <option value="">{{ __('Select') }}</option>
                        @foreach ($this->procedureTypes as $procedureType)
                            <option value="{{ $procedureType->id }}">{{ $procedureType->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="procedureTypeId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Doctor') }}</flux:label>
                    <flux:select wire:model="doctorId">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($this->doctors as $doctor)
                            <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="doctorId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Expected date of delivery') }}</flux:label>
                    <flux:input wire:model="expectedDeliveryDate" type="date" required />
                    <flux:error name="expectedDeliveryDate" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Total package') }}</flux:label>
                    <flux:input wire:model="fullAmount" type="number" step="0.01" min="0" required />
                    <flux:error name="fullAmount" />
                </flux:field>
            </div>

            @if ($editingProcedureId === null)
                <div class="space-y-4">
                    <flux:checkbox wire:model.live="hasAdvancePayment" label="{{ __('Advance payment') }}" />

                    @if ($hasAdvancePayment)
                        <flux:field>
                            <flux:label>{{ __('Advance amount') }}</flux:label>
                            <flux:input wire:model="advancePayment" type="number" step="0.01" min="0" required />
                            <flux:error name="advancePayment" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Payment mode') }}</flux:label>
                            <flux:select wire:model="advancePaymentMode" required>
                                @foreach (App\Enums\PaymentMode::cases() as $mode)
                                    <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="advancePaymentMode" />
                        </flux:field>
                    @endif
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeProcedureModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ $editingProcedureId ? __('Update') : __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showPaymentModal" class="w-full {{ $excludeFromCurrentShift ? 'max-w-3xl' : 'max-w-sm' }}">
        <flux:heading level="2">{{ __('Add Payment') }}</flux:heading>

        <form wire:submit="savePayment" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Payment amount') }}</flux:label>
                <flux:input wire:model="paymentAmount" type="number" step="0.01" min="0" required autofocus />
                <flux:error name="paymentAmount" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Payment mode') }}</flux:label>
                <flux:select wire:model="paymentMode" required>
                    @foreach (App\Enums\PaymentMode::cases() as $mode)
                        <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="paymentMode" />
            </flux:field>

            @if (auth()->user()?->isAdmin())
                <div class="space-y-4">
                    <flux:checkbox
                        wire:model.live="excludeFromCurrentShift"
                        label="{{ __('Do not add to current shift') }}"
                    />

                    @if ($excludeFromCurrentShift)
                        <div class="space-y-3">
                            <flux:text class="text-sm text-zinc-500">
                                {{ __('Select the previous shift this payment belongs to.') }}
                            </flux:text>

                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>{{ __('Opened By') }}</flux:table.column>
                                    <flux:table.column>{{ __('Opened At') }}</flux:table.column>
                                    <flux:table.column>{{ __('Closed At') }}</flux:table.column>
                                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                                </flux:table.columns>

                                <flux:table.rows>
                                    @forelse ($this->previousShifts as $previousShift)
                                        <flux:table.row
                                            wire:key="previous-shift-{{ $previousShift->id }}"
                                            class="{{ $selectedPreviousShiftId === $previousShift->id ? 'bg-zinc-100 dark:bg-zinc-800' : '' }}"
                                        >
                                            <flux:table.cell>{{ $previousShift->user->name }}</flux:table.cell>
                                            <flux:table.cell>{{ $previousShift->opened_at->format('Y-m-d H:i') }}</flux:table.cell>
                                            <flux:table.cell>{{ $previousShift->closed_at?->format('Y-m-d H:i') ?? '-' }}</flux:table.cell>
                                            <flux:table.cell class="text-right">
                                                <flux:button
                                                    type="button"
                                                    size="sm"
                                                    variant="{{ $selectedPreviousShiftId === $previousShift->id ? 'primary' : 'ghost' }}"
                                                    wire:click="selectPreviousShift({{ $previousShift->id }})"
                                                >
                                                    {{ $selectedPreviousShiftId === $previousShift->id ? __('Selected') : __('Select') }}
                                                </flux:button>
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @empty
                                        <flux:table.row>
                                            <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                                {{ __('No previous shifts found.') }}
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforelse
                                </flux:table.rows>
                            </flux:table>

                            <flux:error name="selectedPreviousShiftId" />

                        </div>
                    @endif
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closePaymentModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showViewModal" class="w-full max-w-2xl">
        @if ($this->viewedProcedure)
            @php
                $viewedPaid = (float) ($this->viewedProcedure->payments_sum_amount ?? $this->viewedProcedure->totalPaid());
                $viewedBalance = $this->viewedProcedure->full_amount - $viewedPaid;
                $isAdmin = auth()->user()?->isAdmin() === true;
                $canDiscardPayments = $isAdmin;
            @endphp

            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <flux:heading level="2">{{ $this->viewedProcedure->patient->name }}</flux:heading>
                    <flux:text class="text-zinc-500">
                        {{ $this->viewedProcedure->patient->mrn ?? __('No MRN') }} · {{ $this->viewedProcedure->name }}
                    </flux:text>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    @if ($viewedBalance > 0)
                        <flux:button
                            size="sm"
                            variant="primary"
                            icon="check-circle"
                            wire:click="markPaid({{ $this->viewedProcedure->id }})"
                            wire:confirm="{{ __('Mark this procedure as paid for :amount without adding it to any shift?', ['amount' => number_format($viewedBalance, 2)]) }}"
                        >
                            {{ __('Mark Paid') }}
                        </flux:button>
                    @endif
                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $this->viewedProcedure->id }})">
                        {{ __('Edit') }}
                    </flux:button>
                    @if ($isAdmin)
                        <flux:button
                            size="sm"
                            variant="danger"
                            icon="trash"
                            wire:click="deleteProcedure({{ $this->viewedProcedure->id }})"
                            wire:confirm="{{ __('Delete this procedure and all its payments? This cannot be undone.') }}"
                        >
                            {{ __('Delete') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                <div>
                    <flux:text class="text-zinc-500">{{ __('Cell') }}</flux:text>
                    <flux:text>{{ $this->viewedProcedure->patient->contactPhone() ?? '-' }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-zinc-500">{{ __('Husband') }}</flux:text>
                    <flux:text>{{ $this->viewedProcedure->patient->husband_name ?? '-' }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-zinc-500">{{ __('Age') }}</flux:text>
                    <flux:text>{{ $this->viewedProcedure->patient->age ?? '-' }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-zinc-500">{{ __('Doctor') }}</flux:text>
                    <flux:text>{{ $this->viewedProcedure->doctor?->name ?? '-' }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-zinc-500">{{ __('Expected date of delivery') }}</flux:text>
                    <flux:text>{{ $this->viewedProcedure->expected_delivery_date?->format('M j, Y') ?? '-' }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-zinc-500">{{ __('Total package') }}</flux:text>
                    <flux:text>{{ number_format($this->viewedProcedure->full_amount, 2) }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-zinc-500">{{ __('Balance') }}</flux:text>
                    <div class="flex items-center gap-2">
                        <flux:text>{{ number_format($viewedBalance, 2) }}</flux:text>
                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="printer"
                            :href="route('reception.procedures.print', $this->viewedProcedure)"
                            target="_blank"
                        >
                            {{ __('Print') }}
                        </flux:button>
                    </div>
                </div>
            </div>

            <div class="mt-6 border-t pt-6">
                <flux:heading level="3" class="mb-4">{{ __('Steps') }}</flux:heading>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @if ($this->viewedProcedure->isAdmitted())
                        <div class="space-y-1">
                            <flux:button
                                variant="filled"
                                icon="home-modern"
                                disabled
                                class="w-full"
                            >
                                {{ __('1. Admission') }}
                            </flux:button>
                            <flux:text class="text-center text-xs text-zinc-500">
                                {{ __('Completed') }}
                            </flux:text>
                        </div>
                    @else
                        <flux:button
                            variant="primary"
                            icon="home-modern"
                            wire:click="addAdmission({{ $this->viewedProcedure->id }})"
                            class="w-full"
                        >
                            {{ __('1. Add Admission') }}
                        </flux:button>
                    @endif

                    @php
                        $hasPrintDocuments = ($this->viewedProcedure->procedureType?->documents?->isNotEmpty()) ?? false;
                        $isFilePrinted = $this->viewedProcedure->isFilePrinted();
                    @endphp

                    @if ($hasPrintDocuments)
                        <div class="space-y-1">
                            <flux:button
                                variant="{{ $isFilePrinted ? 'filled' : 'primary' }}"
                                icon="printer"
                                :href="route('reception.procedures.file', $this->viewedProcedure)"
                                target="_blank"
                                class="w-full"
                                wire:click="$refresh"
                            >
                                {{ $isFilePrinted ? __('2. File Printed') : __('2. Print File') }}
                            </flux:button>
                            @if ($isFilePrinted)
                                <flux:text class="text-center text-xs text-zinc-500">
                                    {{ __('Printed :date', ['date' => $this->viewedProcedure->file_printed_at->format('M j, g:i A')]) }}
                                </flux:text>
                            @endif
                        </div>
                    @else
                        <div class="space-y-1">
                            <flux:button
                                variant="filled"
                                icon="printer"
                                disabled
                                class="w-full"
                            >
                                {{ __('2. Print File') }}
                            </flux:button>
                            <flux:text class="text-center text-xs text-zinc-500">
                                {{ __('No documents linked') }}
                            </flux:text>
                        </div>
                    @endif

                    <flux:button
                        variant="{{ $showPaymentLedger ? 'primary' : 'filled' }}"
                        icon="banknotes"
                        wire:click="togglePaymentLedger"
                        class="w-full"
                    >
                        {{ __('3. Payment Ledger') }}
                    </flux:button>
                </div>

                @if ($this->viewedProcedure->isAdmitted())
                    <div class="mt-4 space-y-1 rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex items-center gap-2">
                            <flux:badge size="sm" color="{{ $this->viewedProcedure->status->badgeColor() }}">
                                {{ $this->viewedProcedure->status->label() }}
                            </flux:badge>
                        </div>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Room') }}: {{ $this->viewedProcedure->room_number ?? '-' }} ·
                            {{ __('CNIC') }}: {{ $this->viewedProcedure->patient->cnic ?? '-' }}
                        </flux:text>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Admitted on :date', ['date' => $this->viewedProcedure->admitted_at->format('M j, Y g:i A')]) }}
                        </flux:text>
                        @if ($this->viewedProcedure->isDischarged())
                            <flux:text class="text-zinc-500 dark:text-zinc-400">
                                {{ __('Discharged on :date', ['date' => $this->viewedProcedure->discharged_at->format('M j, Y g:i A')]) }}
                            </flux:text>
                        @endif
                    </div>
                @else
                    <div class="mt-4 flex items-center gap-2">
                        <flux:badge size="sm" color="{{ $this->viewedProcedure->status->badgeColor() }}">
                            {{ $this->viewedProcedure->status->label() }}
                        </flux:badge>
                    </div>
                @endif
            </div>

            @if ($showPaymentLedger)
                <div class="mt-6 border-t pt-6">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <flux:heading level="3">{{ __('Payment Ledger') }}</flux:heading>
                        <flux:button size="sm" variant="primary" icon="banknotes" wire:click="addPayment({{ $this->viewedProcedure->id }})">
                            {{ __('Add Payment') }}
                        </flux:button>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
                            <flux:table.column>{{ __('Amount') }}</flux:table.column>
                            <flux:table.column>{{ __('Mode') }}</flux:table.column>
                            <flux:table.column>{{ __('Recorded By') }}</flux:table.column>
                            <flux:table.column>{{ __('Shift') }}</flux:table.column>
                            @if ($canDiscardPayments)
                                <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                            @endif
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->viewedProcedure->payments as $payment)
                                <flux:table.row wire:key="procedure-ledger-payment-{{ $payment->id }}">
                                    <flux:table.cell>{{ $payment->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                                    <flux:table.cell>
                                        <span @class(['line-through text-zinc-400' => $payment->isDiscarded()])>
                                            {{ number_format($payment->amount, 2) }}
                                        </span>
                                        @if ($payment->isDiscarded())
                                            <flux:badge size="sm" color="red" class="ml-2">{{ __('Discarded') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $payment->mode?->label() ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ $payment->creator?->name ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ $payment->shift?->opened_at->format('Y-m-d H:i') ?? '-' }}</flux:table.cell>
                                    @if ($canDiscardPayments)
                                        <flux:table.cell class="text-right">
                                            @if (! $payment->isDiscarded())
                                                <flux:button
                                                    size="sm"
                                                    variant="danger"
                                                    icon="arrow-uturn-left"
                                                    wire:click="discardPayment({{ $payment->id }})"
                                                    wire:confirm="{{ __('Discard this payment of :amount? It will no longer count as paid or toward shift sales.', ['amount' => number_format($payment->amount, 2)]) }}"
                                                >
                                                    {{ __('Discard') }}
                                                </flux:button>
                                            @endif
                                        </flux:table.cell>
                                    @endif
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="{{ $canDiscardPayments ? 6 : 5 }}" class="text-center text-zinc-500">
                                        {{ __('No payments recorded.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>

                    <div class="mt-4 flex justify-end border-t pt-4 text-sm">
                        <div class="text-right">
                            <flux:text class="text-zinc-500">{{ __('Total Paid') }}</flux:text>
                            <flux:text class="text-lg font-semibold">{{ number_format($viewedPaid, 2) }}</flux:text>
                        </div>
                    </div>
                </div>
            @endif
        @endif

        <div class="mt-6 flex justify-end gap-3">
            <flux:button type="button" variant="ghost" wire:click="closeViewModal">
                {{ __('Close') }}
            </flux:button>
        </div>
    </flux:modal>

    <flux:modal wire:model="showAdmissionModal" class="w-full max-w-sm">
        <flux:heading level="2">{{ __('Add Admission') }}</flux:heading>

        <form wire:submit="admitPatient" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('CNIC') }}</flux:label>
                <flux:input wire:model="admissionCnic" type="text" required autofocus />
                <flux:error name="admissionCnic" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Room number') }}</flux:label>
                <flux:select wire:model="admissionRoomId" required>
                    <option value="">{{ __('Select a room') }}</option>
                    @foreach ($this->rooms as $room)
                        @php
                            $occupiedByOther = $room->isOccupied()
                                && $room->currentAdmission?->id !== $admittingProcedureId;
                        @endphp
                        <option value="{{ $room->id }}" @disabled($occupiedByOther)>
                            {{ $room->number }}
                            @if ($occupiedByOther)
                                — {{ __('Occupied') }}
                            @elseif ($room->isOccupied())
                                — {{ __('Current') }}
                            @else
                                — {{ __('Free') }}
                            @endif
                        </option>
                    @endforeach
                </flux:select>
                <flux:error name="admissionRoomId" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeAdmissionModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Admit') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
