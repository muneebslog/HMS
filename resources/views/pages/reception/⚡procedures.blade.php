<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedurePayment;
use App\Models\Shift;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Procedures')] class extends Component
{
    public bool $showProcedureModal = false;

    public bool $showPaymentModal = false;

    public ?int $editingProcedureId = null;

    #[Validate]
    public string $patientName = '';

    #[Validate]
    public string $patientPhone = '';

    #[Validate]
    public string $patientGender = '';

    #[Validate]
    public ?int $patientAge = null;

    #[Validate]
    public string $procedureName = '';

    #[Validate]
    public string $fullAmount = '';

    #[Validate]
    public string $roomNumber = '';

    #[Validate]
    public ?int $doctorId = null;

    #[Validate]
    public string $advancePayment = '';

    #[Validate]
    public string $paymentAmount = '';

    /**
     * Get the validation rules for the procedure form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'patientName' => ['required', 'string', 'max:255'],
            'patientPhone' => ['required', 'string', 'max:255'],
            'patientGender' => ['required', 'string', 'in:male,female,other'],
            'patientAge' => ['required', 'integer', 'min:0', 'max:150'],
            'procedureName' => ['required', 'string', 'max:255'],
            'fullAmount' => ['required', 'numeric', 'min:0'],
            'roomNumber' => ['required', 'string', 'max:255'],
            'doctorId' => ['nullable', 'integer', 'exists:doctors,id'],
            'advancePayment' => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) {
                    if ((float) $value > (float) $this->fullAmount) {
                        $fail(__('Advance payment cannot exceed the full amount.'));
                    }
                },
            ],
            'paymentAmount' => ['required', 'numeric', 'min:0'],
        ];
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

        $procedure = Procedure::with('patient')->findOrFail($id);

        $this->patientName = $procedure->patient->name;
        $this->patientPhone = $procedure->patient->phone ?? '';
        $this->patientGender = $procedure->patient->gender ?? '';
        $this->patientAge = $procedure->patient->age;
        $this->procedureName = $procedure->name;
        $this->fullAmount = (string) $procedure->full_amount;
        $this->roomNumber = $procedure->room_number;
        $this->doctorId = $procedure->doctor_id;
        $this->advancePayment = '0';

        $this->showProcedureModal = true;
    }

    /**
     * Open the modal to add a payment to a procedure.
     */
    public function addPayment(int $id): void
    {
        $this->resetPaymentForm();
        $this->editingProcedureId = $id;
        $this->showPaymentModal = true;
    }

    /**
     * Reset the procedure form fields.
     */
    private function resetProcedureForm(): void
    {
        $this->reset([
            'patientName',
            'patientPhone',
            'patientGender',
            'patientAge',
            'procedureName',
            'fullAmount',
            'roomNumber',
            'doctorId',
            'advancePayment',
        ]);
        $this->resetErrorBag();
    }

    /**
     * Reset the payment form fields.
     */
    private function resetPaymentForm(): void
    {
        $this->reset(['paymentAmount']);
        $this->resetErrorBag();
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
     * Persist a new or updated procedure.
     */
    public function saveProcedure(): void
    {
        $validated = $this->validate([
            'patientName' => $this->rules()['patientName'],
            'patientPhone' => $this->rules()['patientPhone'],
            'patientGender' => $this->rules()['patientGender'],
            'patientAge' => $this->rules()['patientAge'],
            'procedureName' => $this->rules()['procedureName'],
            'fullAmount' => $this->rules()['fullAmount'],
            'roomNumber' => $this->rules()['roomNumber'],
            'doctorId' => $this->rules()['doctorId'],
            'advancePayment' => $this->editingProcedureId === null ? $this->rules()['advancePayment'] : ['nullable'],
        ]);

        $shift = Shift::currentForUser(auth()->id());

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('Please open a shift first.'));

            return;
        }

        if ($this->editingProcedureId !== null) {
            $this->updateProcedure($validated);
        } else {
            $this->storeProcedure($validated, $shift);
        }

        $this->closeProcedureModal();
    }

    /**
     * Store a new procedure with patient and advance payment.
     *
     * @param array<string, mixed> $validated
     */
    private function storeProcedure(array $validated, Shift $shift): void
    {
        DB::transaction(function () use ($validated, $shift) {
            $patient = Patient::create([
                'name' => $validated['patientName'],
                'phone' => $validated['patientPhone'],
                'age' => $validated['patientAge'],
                'gender' => $validated['patientGender'],
            ]);

            $procedure = Procedure::create([
                'patient_id' => $patient->id,
                'name' => $validated['procedureName'],
                'full_amount' => $validated['fullAmount'],
                'room_number' => $validated['roomNumber'],
                'doctor_id' => $validated['doctorId'],
                'created_by' => auth()->id(),
                'shift_id' => $shift->id,
            ]);

            if ((float) $validated['advancePayment'] > 0) {
                ProcedurePayment::create([
                    'procedure_id' => $procedure->id,
                    'amount' => $validated['advancePayment'],
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
     * @param array<string, mixed> $validated
     */
    private function updateProcedure(array $validated): void
    {
        $procedure = Procedure::with('payments')->findOrFail($this->editingProcedureId);

        if ((float) $validated['fullAmount'] < $procedure->totalPaid()) {
            Flux::toast(variant: 'danger', text: __('Full amount cannot be less than the total paid.'));

            return;
        }

        DB::transaction(function () use ($procedure, $validated) {
            $procedure->patient->update([
                'name' => $validated['patientName'],
                'phone' => $validated['patientPhone'],
                'age' => $validated['patientAge'],
                'gender' => $validated['patientGender'],
            ]);

            $procedure->update([
                'name' => $validated['procedureName'],
                'full_amount' => $validated['fullAmount'],
                'room_number' => $validated['roomNumber'],
                'doctor_id' => $validated['doctorId'],
            ]);
        });

        Flux::toast(variant: 'success', text: __('Procedure updated.'));
    }

    /**
     * Store a new payment for the selected procedure.
     */
    public function savePayment(): void
    {
        $validated = $this->validate([
            'paymentAmount' => $this->rules()['paymentAmount'],
        ]);

        $shift = Shift::currentForUser(auth()->id());

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('Please open a shift first.'));

            return;
        }

        $procedure = Procedure::with('payments')->findOrFail($this->editingProcedureId);

        if ((float) $validated['paymentAmount'] > $procedure->balance()) {
            Flux::toast(variant: 'danger', text: __('Payment amount cannot exceed the remaining balance.'));

            return;
        }

        ProcedurePayment::create([
            'procedure_id' => $procedure->id,
            'amount' => $validated['paymentAmount'],
            'created_by' => auth()->id(),
            'shift_id' => $shift->id,
        ]);

        $this->closePaymentModal();

        Flux::toast(variant: 'success', text: __('Payment added.'));
    }

    /**
     * Get the currently open shift for the user.
     */
    #[Computed]
    public function currentShift(): ?Shift
    {
        return Shift::currentForUser(auth()->id());
    }

    /**
     * Get the list of procedures with relations.
     *
     * @return Collection<int, Procedure>
     */
    #[Computed]
    public function procedures(): Collection
    {
        return Procedure::with(['patient', 'doctor', 'payments'])->latest()->get();
    }

    /**
     * Get the list of doctors.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function doctors(): Collection
    {
        return Doctor::orderBy('name')->get();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Procedures') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="create">
                {{ __('Add new procedure') }}
            </flux:button>
        </div>

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
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Procedure') }}</flux:table.column>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Room') }}</flux:table.column>
                    <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                    <flux:table.column>{{ __('Full Amount') }}</flux:table.column>
                    <flux:table.column>{{ __('Paid') }}</flux:table.column>
                    <flux:table.column>{{ __('Balance') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->procedures as $procedure)
                        <flux:table.row wire:key="procedure-{{ $procedure->id }}">
                            <flux:table.cell>{{ $procedure->name }}</flux:table.cell>
                            <flux:table.cell>{{ $procedure->patient->name }}</flux:table.cell>
                            <flux:table.cell>{{ $procedure->room_number }}</flux:table.cell>
                            <flux:table.cell>{{ $procedure->doctor?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($procedure->full_amount, 2) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($procedure->totalPaid(), 2) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($procedure->balance(), 2) }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($procedure->isPaid())
                                    <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button size="sm" variant="ghost" icon="banknotes" wire:click="addPayment({{ $procedure->id }})" />
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $procedure->id }})" />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="9" class="text-center text-zinc-500">
                                {{ __('No procedures found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>

    <flux:modal wire:model="showProcedureModal" class="w-full max-w-2xl">
        <flux:heading level="2">
            {{ $editingProcedureId ? __('Edit Procedure') : __('Add New Procedure') }}
        </flux:heading>

        <form wire:submit="saveProcedure" class="mt-6 space-y-6">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Patient name') }}</flux:label>
                    <flux:input wire:model="patientName" type="text" required />
                    <flux:error name="patientName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Phone number') }}</flux:label>
                    <flux:input wire:model="patientPhone" type="text" required />
                    <flux:error name="patientPhone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Age') }}</flux:label>
                    <flux:input wire:model="patientAge" type="number" min="0" max="150" required />
                    <flux:error name="patientAge" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Gender') }}</flux:label>
                    <flux:select wire:model="patientGender" required>
                        <option value="">{{ __('Select') }}</option>
                        <option value="male">{{ __('Male') }}</option>
                        <option value="female">{{ __('Female') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </flux:select>
                    <flux:error name="patientGender" />
                </flux:field>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Procedure name') }}</flux:label>
                    <flux:input wire:model="procedureName" type="text" required />
                    <flux:error name="procedureName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Room number') }}</flux:label>
                    <flux:input wire:model="roomNumber" type="text" required />
                    <flux:error name="roomNumber" />
                </flux:field>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Full amount') }}</flux:label>
                    <flux:input wire:model="fullAmount" type="number" step="0.01" min="0" required />
                    <flux:error name="fullAmount" />
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
            </div>

            @if ($editingProcedureId === null)
                <flux:field>
                    <flux:label>{{ __('Advance payment') }}</flux:label>
                    <flux:input wire:model="advancePayment" type="number" step="0.01" min="0" required />
                    <flux:error name="advancePayment" />
                </flux:field>
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

    <flux:modal wire:model="showPaymentModal" class="w-full max-w-sm">
        <flux:heading level="2">{{ __('Add Payment') }}</flux:heading>

        <form wire:submit="savePayment" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Payment amount') }}</flux:label>
                <flux:input wire:model="paymentAmount" type="number" step="0.01" min="0" required autofocus />
                <flux:error name="paymentAmount" />
            </flux:field>

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
</div>
