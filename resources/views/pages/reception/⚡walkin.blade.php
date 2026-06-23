<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Models\Shift;
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

    /**
     * Get the validation rules for the walk-in form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'patientName' => ['required', 'string', 'max:255'],
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
            'selectedServiceId',
            'selectedDoctorId',
            'items',
            'editingItemIndex',
            'editingItemPrice',
            'showPriceModal',
        ]);
        $this->resetValidation();
    }

    /**
     * Save the walk-in services as an invoice.
     */
    public function saveInvoice(): void
    {
        $this->validate([
            'patientName' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'items.*.doctor_id' => ['nullable', 'integer', 'exists:doctors,id'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
        ]);

        $shift = Shift::currentForUser(auth()->id());

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('Please open a shift first.'));

            return;
        }

        $invoice = DB::transaction(function () use ($shift) {
            $patient = Patient::create(['name' => $this->patientName]);

            $invoice = Invoice::create([
                'patient_id' => $patient->id,
                'invoice_number' => Invoice::generateNumber(),
                'total' => $this->totalPrice,
                'status' => 'paid',
                'created_by' => auth()->id(),
                'shift_id' => $shift->id,
            ]);

            foreach ($this->items as $item) {
                $invoiceItem = InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_id' => $item['service_id'],
                    'doctor_id' => $item['doctor_id'],
                    'service_name' => $item['service_name'],
                    'doctor_name' => $item['doctor_name'],
                    'price' => $item['price'],
                ]);

                app(QueueService::class)->generateToken($invoiceItem);
            }

            return $invoice;
        });

        $this->clear();

        Flux::toast(variant: 'success', text: __('Invoice :number saved.', ['number' => $invoice->invoice_number]));
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
        return Service::orderBy('name')->get();
    }

    /**
     * Get the doctors related to the selected service.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function availableDoctors(): Collection
    {
        if ($this->currentService === null || $this->currentService->is_standalone) {
            return new Collection();
        }

        return Doctor::whereHas('servicePrices', function ($query) {
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

        $shift = Shift::currentForUser(auth()->id());

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

        $base = $queue?->last_token_number ?? 0;

        $precedingSameQueue = 0;

        for ($i = 0; $i < $index; $i++) {
            if (($this->items[$i]['service_id'] ?? null) === $service->id
                && ($this->items[$i]['doctor_id'] ?? null) === $doctorId) {
                $precedingSameQueue++;
            }
        }

        return $base + $precedingSameQueue + 1;
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Walk-in') }}</flux:heading>
        </div>

        <flux:card>
            <form >
                <flux:field class="w-full">
                    <flux:label>{{ __('Patient name') }}</flux:label>
                    <flux:input wire:model="patientName" type="text" required placeholder="Patient Name..." />
                    <flux:error name="patientName" />
                </flux:field>

            </form>
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
                <div class="mt-4 flex justify-end text-lg font-semibold">
                    {{ __('Total') }}: {{ number_format($this->totalPrice, 2) }}
                </div>
            @endif

            <div class="mt-6 flex gap-3">
                <flux:button type="button" variant="primary" icon="document-check" wire:click="saveInvoice">
                    {{ __('Save invoice') }}
                </flux:button>
                <flux:button type="button" variant="outline" icon="printer" x-on:click="window.print()">
                    {{ __('Print') }}
                </flux:button>
                <flux:button type="button" variant="ghost" wire:click="clear">
                    {{ __('Reset') }}
                </flux:button>
            </div>
        </flux:card>
    </div>

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
