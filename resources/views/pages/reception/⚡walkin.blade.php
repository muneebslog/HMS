<?php

use App\Actions\CancelDripCharge;
use App\Actions\CreatePrintJob;
use App\Actions\MarkDripChargePaid;
use App\Enums\DripChargeStatus;
use App\Enums\PaymentMode;
use App\Enums\TokenResetType;
use App\Livewire\Concerns\InteractsWithPatientIntake;
use App\Models\Doctor;
use App\Models\DripCharge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Services\PatientIntakeService;
use App\Services\QueueService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Walk-in')] class extends Component
{
    use InteractsWithPatientIntake;

    #[Validate]
    public string $patientName = '';

    #[Validate]
    public ?int $selectedServiceId = null;

    #[Validate]
    public ?int $selectedDoctorId = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $items = [];

    public ?int $editingItemIndex = null;

    public string $editingItemPrice = '';

    public bool $showPriceModal = false;

    public bool $showDripPayModal = false;

    public ?int $payingDripChargeId = null;

    public string $dripPayPrice = '';

    #[Validate]
    public string $paymentMode = 'cash';

    public string $dripPaymentMode = 'cash';

    /**
     * Get the validation rules for the walk-in form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'patientName' => ['required', 'string', 'max:255'],
            ...$this->patientIntakePhoneRules(),
            'selectedServiceId' => ['required', 'integer', 'exists:services,id'],
            'selectedDoctorId' => [
                Rule::requiredIf(fn () => $this->currentService !== null && ! $this->currentService->is_standalone),
                'nullable',
                'integer',
                Rule::when(
                    fn () => $this->currentService !== null && ! $this->currentService->is_standalone,
                    [Rule::exists('service_prices', 'doctor_id')->where('service_id', $this->selectedServiceId)]
                ),
            ],
            'paymentMode' => ['required', 'string', 'in:'.implode(',', PaymentMode::values())],
            'dripPaymentMode' => ['required', 'string', 'in:'.implode(',', PaymentMode::values())],
        ];
    }

    /**
     * Reset the doctor selection when the service changes.
     */
    public function updatedSelectedServiceId(): void
    {
        $this->selectedDoctorId = null;
        $this->resetValidation('selectedDoctorId');
    }

    /**
     * Add the selected service to the list.
     */
    public function add(): void
    {
        $validated = $this->validate();

        $service = Service::find($validated['selectedServiceId']);

        if (! $service instanceof Service) {
            Flux::toast(variant: 'danger', text: __('Service not found.'));

            return;
        }

        $doctor = null;

        if (! $service->is_standalone) {
            $doctor = Doctor::find($validated['selectedDoctorId']);

            if (! $doctor instanceof Doctor) {
                Flux::toast(variant: 'danger', text: __('Doctor not found.'));

                return;
            }
        }

        $price = ServicePrice::query()
            ->where('service_id', $service->id)
            ->when(
                $doctor,
                fn ($query) => $query->where('doctor_id', $doctor->id),
                fn ($query) => $query->whereNull('doctor_id')
            )
            ->first();

        $this->items[] = [
            'service_id' => $service->id,
            'service_name' => $service->name,
            'doctor_id' => $doctor?->id,
            'doctor_name' => $doctor?->name,
            'price' => $price?->price ?? 0,
        ];

        $this->reset(['selectedServiceId', 'selectedDoctorId']);
        $this->resetValidation();

        Flux::toast(variant: 'success', text: __('Service added.'));
    }

    /**
     * Remove a service from the list.
     */
    public function remove(int $index): void
    {
        if (isset($this->items[$index])) {
            unset($this->items[$index]);
            $this->items = array_values($this->items);
        }
    }

    /**
     * Open the price editor for a table row.
     */
    public function editPrice(int $index): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $this->editingItemIndex = $index;
        $this->editingItemPrice = (string) $this->items[$index]['price'];
        $this->showPriceModal = true;
    }

    /**
     * Save the updated price to the current receipt.
     */
    public function updatePrice(): void
    {
        if ($this->editingItemIndex === null || ! isset($this->items[$this->editingItemIndex])) {
            $this->resetPriceModal();

            return;
        }

        $validated = $this->validate([
            'editingItemPrice' => ['required', 'numeric', 'min:0'],
        ]);

        $this->items[$this->editingItemIndex]['price'] = (float) $validated['editingItemPrice'];

        $this->resetPriceModal();

        Flux::toast(variant: 'success', text: __('Price updated.'));
    }

    /**
     * Close the price modal and reset its state.
     */
    public function resetPriceModal(): void
    {
        $this->showPriceModal = false;
        $this->editingItemIndex = null;
        $this->editingItemPrice = '';
        $this->resetValidation();
    }

    /**
     * Clear the form and the selected services.
     */
    public function clear(): void
    {
        $this->reset([
            'patientName',
            ...$this->patientIntakeResetFields(),
            'selectedServiceId',
            'selectedDoctorId',
            'items',
            'editingItemIndex',
            'editingItemPrice',
            'showPriceModal',
            'paymentMode',
        ]);
        $this->paymentMode = PaymentMode::Cash->value;
        $this->resetValidation();
    }

    /**
     * Save the walk-in services as an invoice.
     */
    public function saveInvoice(): void
    {
        $validated = $this->validate([
            'patientName' => ['required', 'string', 'max:255'],
            ...$this->patientIntakePhoneRules(),
            'items' => ['required', 'array', 'min:1'],
            'items.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'items.*.doctor_id' => ['nullable', 'integer', 'exists:doctors,id'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'paymentMode' => $this->rules()['paymentMode'],
        ]);

        $shift = Shift::current();

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('Please open a shift first.'));

            return;
        }

        $invoice = DB::transaction(function () use ($shift, $validated) {
            $patient = $this->resolveIntakePatient(['name' => $this->patientName]);

            if ($this->hasNoPhone && auth()->user() !== null) {
                app(PatientIntakeService::class)->notifyWithoutPhone(
                    auth()->user(),
                    $patient,
                    'walk_in',
                );
            }

            $invoice = Invoice::create([
                'patient_id' => $patient->id,
                'invoice_number' => Invoice::generateNumber(),
                'total' => $this->totalPrice,
                'status' => 'paid',
                'payment_mode' => $validated['paymentMode'],
                'created_by' => auth()->id(),
                'shift_id' => $shift->id,
            ]);

            foreach ($this->items as $item) {
                $servicePrice = ServicePrice::query()
                    ->where('service_id', $item['service_id'])
                    ->when(
                        $item['doctor_id'],
                        fn ($query) => $query->where('doctor_id', $item['doctor_id']),
                        fn ($query) => $query->whereNull('doctor_id')
                    )
                    ->first();

                $invoiceItem = InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_id' => $item['service_id'],
                    'doctor_id' => $item['doctor_id'],
                    'service_name' => $item['service_name'],
                    'doctor_name' => $item['doctor_name'],
                    'price' => $item['price'],
                    'doctor_share' => $servicePrice?->doctor_share,
                ]);

                app(QueueService::class)->generateToken($invoiceItem);
            }

            return $invoice;
        });

        app(CreatePrintJob::class)->create($invoice);

        $this->clear();

        Flux::toast(variant: 'success', text: __('Invoice :number saved. Print job queued.', ['number' => $invoice->invoice_number]));
    }

    /**
     * Get the currently selected service.
     */
    #[Computed]
    public function currentService(): ?Service
    {
        if ($this->selectedServiceId === null) {
            return null;
        }

        return Service::find($this->selectedServiceId);
    }

    /**
     * Get the list of services.
     *
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::active()->orderBy('name')->get();
    }

    /**
     * Get the active doctors related to the selected service.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function availableDoctors(): Collection
    {
        if ($this->currentService === null || $this->currentService->is_standalone) {
            return new Collection();
        }

        return Doctor::active()->whereHas('servicePrices', function ($query) {
            $query->where('service_id', $this->currentService->id);
        })->orderBy('name')->get();
    }

    /**
     * Get the total price of the selected services.
     */
    #[Computed]
    public function totalPrice(): float
    {
        return collect($this->items)->sum('price');
    }

    /**
     * Pending drip charges waiting for walk-in payment.
     *
     * @return Collection<int, DripCharge>
     */
    #[Computed]
    public function pendingDripCharges(): Collection
    {
        return DripCharge::query()
            ->with(['patient', 'service', 'doctor', 'suggestedBy.doctor', 'medicationOrder.drips'])
            ->where('status', DripChargeStatus::Pending)
            ->latest()
            ->get();
    }

    /**
     * Open the drip payment modal so reception can confirm or enter the price.
     */
    public function openDripPay(int $chargeId): void
    {
        $charge = DripCharge::query()
            ->where('status', DripChargeStatus::Pending)
            ->find($chargeId);

        if ($charge === null) {
            Flux::toast(variant: 'danger', text: __('Drip charge not found or already paid.'));
            unset($this->pendingDripCharges);

            return;
        }

        $this->payingDripChargeId = $charge->id;
        $this->dripPayPrice = $charge->suggested_price !== null
            ? (string) $charge->suggested_price
            : '';
        $this->showDripPayModal = true;
        $this->resetValidation(['dripPayPrice', 'dripPaymentMode']);
    }

    /**
     * Close the drip payment modal.
     */
    public function resetDripPayModal(): void
    {
        $this->showDripPayModal = false;
        $this->payingDripChargeId = null;
        $this->dripPayPrice = '';
        $this->resetValidation(['dripPayPrice']);
    }

    /**
     * Collect payment for a pending drip charge and print the slip.
     */
    public function confirmDripPaid(): void
    {
        $this->validate([
            'dripPayPrice' => ['required', 'numeric', 'min:0'],
            'dripPaymentMode' => $this->rules()['dripPaymentMode'],
        ]);

        if ($this->payingDripChargeId === null) {
            $this->resetDripPayModal();

            return;
        }

        $shift = Shift::current();

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('Please open a shift first.'));

            return;
        }

        $charge = DripCharge::query()
            ->where('status', DripChargeStatus::Pending)
            ->find($this->payingDripChargeId);

        if ($charge === null) {
            Flux::toast(variant: 'danger', text: __('Drip charge not found or already paid.'));
            $this->resetDripPayModal();
            unset($this->pendingDripCharges);

            return;
        }

        $user = auth()->user();

        if ($user === null) {
            Flux::toast(variant: 'danger', text: __('You must be logged in.'));

            return;
        }

        try {
            $invoice = app(MarkDripChargePaid::class)->handle(
                $charge,
                $shift,
                $user,
                PaymentMode::from($this->dripPaymentMode),
                (float) $this->dripPayPrice,
            );
        } catch (\InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: __($exception->getMessage()));
            $this->resetDripPayModal();
            unset($this->pendingDripCharges);

            return;
        }

        $this->resetDripPayModal();
        unset($this->pendingDripCharges);

        Flux::toast(variant: 'success', text: __('Invoice :number saved. Print job queued.', ['number' => $invoice->invoice_number]));
    }

    /**
     * Cancel a pending drip charge and related drip lines.
     */
    public function cancelDrip(int $chargeId): void
    {
        $charge = DripCharge::query()
            ->where('status', DripChargeStatus::Pending)
            ->find($chargeId);

        if ($charge === null) {
            Flux::toast(variant: 'danger', text: __('Drip charge not found or already handled.'));
            unset($this->pendingDripCharges);

            return;
        }

        try {
            app(CancelDripCharge::class)->handle($charge);
        } catch (\InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: __($exception->getMessage()));
            unset($this->pendingDripCharges);

            return;
        }

        if ($this->payingDripChargeId === $chargeId) {
            $this->resetDripPayModal();
        }

        unset($this->pendingDripCharges);

        Flux::toast(variant: 'success', text: __('Drip cancelled.'));
    }

    /**
     * Get the expected token number for the item at the given index.
     */
    public function expectedTokenForItem(int $index): ?int
    {
        $item = $this->items[$index] ?? null;

        if ($item === null) {
            return null;
        }

        $service = Service::find($item['service_id']);

        if (! $service instanceof Service) {
            return null;
        }

        $doctorId = $item['doctor_id'] ?? null;

        $shift = Shift::current();

        if ($shift === null) {
            return null;
        }

        $queue = ServiceQueue::where('service_id', $service->id)
            ->where('doctor_id', $doctorId)
            ->where('status', 'open')
            ->when(
                $service->token_reset_type === TokenResetType::Shift,
                fn ($query) => $query->where('shift_id', $shift->id),
                fn ($query) => $query->whereDate('date', $shift->opened_at)
            )
            ->first();

        $base = $queue !== null
            ? app(QueueService::class)->peekNextTokenNumber($queue)
            : 1;

        $precedingSameQueue = 0;

        for ($i = 0; $i < $index; $i++) {
            if (($this->items[$i]['service_id'] ?? null) === $service->id
                && ($this->items[$i]['doctor_id'] ?? null) === $doctorId) {
                $precedingSameQueue++;
            }
        }

        return $base + $precedingSameQueue;
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Walk-in') }}</flux:heading>
        </div>

        <flux:card>
            <div class="space-y-4">
                @include('partials.reception.patient-intake')

                @if ($this->shouldShowPatientNameField())
                    <flux:field class="w-full">
                        <flux:label>{{ __('Patient name') }}</flux:label>
                        <flux:input wire:model="patientName" type="text" required placeholder="Patient Name..." />
                        <flux:error name="patientName" />
                    </flux:field>
                @endif
            </div>
        </flux:card>

        <flux:card>
            <flux:heading level="2">
                <form wire:submit="add" class="grid grid-cols-1 items-end gap-6 md:grid-cols-12">
                <flux:field class="md:col-span-5">
                    <flux:label>{{ __('Service') }}</flux:label>
                    <flux:select wire:model.live="selectedServiceId" required>
                        <option value="">{{ __('Select a service') }}</option>
                        @foreach ($this->services as $service)
                            <option value="{{ $service->id }}">{{ $service->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="selectedServiceId" />
                </flux:field>

                @if ($this->currentService && ! $this->currentService->is_standalone)
                    <flux:field class="md:col-span-5">
                        <flux:label>{{ __('Doctor') }}</flux:label>
                        <flux:select wire:model="selectedDoctorId" required>
                            <option value="">{{ __('Select a doctor') }}</option>
                            @foreach ($this->availableDoctors as $doctor)
                                <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="selectedDoctorId" />
                    </flux:field>
                @endif

                <div class="md:col-span-2">
                    <flux:button type="submit" variant="primary" icon="plus">
                        {{ __('Add') }}
                    </flux:button>
                </div>
            </form></flux:heading>

            @if ($patientName)
                <flux:text class="mt-1">{{ __('Patient') }}: {{ $patientName }}</flux:text>
            @endif

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('Service') }}</flux:table.column>
                    <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                    <flux:table.column>{{ __('Token') }}</flux:table.column>
                    <flux:table.column>{{ __('Price') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->items as $index => $item)
                        <flux:table.row wire:key="item-{{ $index }}">
                            <flux:table.cell>{{ $item['service_name'] }}</flux:table.cell>
                            <flux:table.cell>{{ $item['doctor_name'] ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $this->expectedTokenForItem($index) ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($item['price'], 2) }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil-square"
                                    wire:click="editPrice({{ $index }})"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="remove({{ $index }})"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                {{ __('No services added yet.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            @if (count($this->items) > 0)
                <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <flux:field class="w-full sm:max-w-xs">
                        <flux:label>{{ __('Payment mode') }}</flux:label>
                        <flux:select wire:model="paymentMode" required>
                            @foreach (App\Enums\PaymentMode::cases() as $mode)
                                <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="paymentMode" />
                    </flux:field>

                    <div class="text-lg font-semibold">
                        {{ __('Total') }}: {{ number_format($this->totalPrice, 2) }}
                    </div>
                </div>
            @endif

            <div class="mt-6 flex gap-3">
                <flux:button type="button" variant="primary" icon="document-check" wire:click="saveInvoice">
                    {{ __('Save invoice') }}
                </flux:button>

                <flux:button type="button" variant="ghost" wire:click="clear">
                    {{ __('Reset') }}
                </flux:button>
            </div>
        </flux:card>

        <div wire:poll.10s>
            <flux:card>
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Pending drip charges') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Drip orders waiting for reception to set a price, collect payment, or cancel.') }}</flux:text>
                    </div>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Patient') }}</flux:table.column>
                        <flux:table.column>{{ __('Service') }}</flux:table.column>
                        <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                        <flux:table.column>{{ __('Price') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->pendingDripCharges as $charge)
                            <flux:table.row wire:key="drip-charge-{{ $charge->id }}">
                                <flux:table.cell>
                                    <div class="font-medium">{{ $charge->patient?->name ?? __('Unknown') }}</div>
                                    <div class="text-xs text-zinc-500">{{ $charge->patient?->mrn ?? __('No MRN') }}</div>
                                </flux:table.cell>
                                <flux:table.cell>{{ $charge->service?->name }}</flux:table.cell>
                                <flux:table.cell>{{ $charge->doctor?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($charge->suggested_price !== null)
                                        {{ number_format($charge->suggested_price, 2) }}
                                    @else
                                        <span class="text-amber-600 dark:text-amber-400">{{ __('Needs price') }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <flux:button
                                            size="sm"
                                            variant="primary"
                                            icon="banknotes"
                                            wire:click="openDripPay({{ $charge->id }})"
                                        >
                                            {{ $charge->suggested_price !== null ? __('Collect payment') : __('Set price') }}
                                        </flux:button>
                                        <flux:button
                                            size="sm"
                                            variant="danger"
                                            icon="x-mark"
                                            wire:click="cancelDrip({{ $charge->id }})"
                                            wire:confirm="{{ __('Cancel this drip? It will be removed from the drip station and ER hold.') }}"
                                        >
                                            {{ __('Cancel drip') }}
                                        </flux:button>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                    {{ __('No pending drip charges.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        </div>
    </div>

    <flux:modal wire:model="showDripPayModal" class="w-full max-w-sm">
        <flux:heading level="2">{{ __('Collect drip payment') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Enter the price, then mark as paid and print the slip.') }}</flux:text>

        <form wire:submit="confirmDripPaid" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Price') }}</flux:label>
                <flux:input
                    wire:model="dripPayPrice"
                    type="number"
                    step="0.01"
                    min="0"
                    required
                    autofocus
                />
                <flux:error name="dripPayPrice" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Payment mode') }}</flux:label>
                <flux:select wire:model="dripPaymentMode">
                    @foreach (App\Enums\PaymentMode::cases() as $mode)
                        <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="dripPaymentMode" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="resetDripPayModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" icon="banknotes">
                    {{ __('Mark paid') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showPriceModal" class="w-full max-w-sm">
        <flux:heading level="2">{{ __('Edit price') }}</flux:heading>

        <form wire:submit="updatePrice" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Price') }}</flux:label>
                <flux:input
                    wire:model="editingItemPrice"
                    type="number"
                    step="0.01"
                    min="0"
                    required
                    autofocus
                />
                <flux:error name="editingItemPrice" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="resetPriceModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('OK') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
