<?php

use App\Models\Doctor;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Services\QueueService;
use App\Actions\CreatePrintJob;
use App\Services\ReservationService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Reservations')] class extends Component
{
    #[Validate]
    public ?int $selectedDoctorId = null;

    #[Validate]
    public string $patientName = '';

    #[Validate]
    public string $patientPhone = '';

    public int $visibleCount = 30;

    public ?int $viewingTokenNumber = null;

    public ?int $viewingTokenId = null;

    public bool $showReserveModal = false;

    public bool $showArrivalModal = false;

    /**
     * Get the validation rules for the reservation form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'selectedDoctorId' => ['required', 'integer', 'exists:doctors,id'],
            'patientName' => ['required', 'string', 'max:255'],
            'patientPhone' => ['required', 'digits:11'],
        ];
    }

    /**
     * Reset the grid when the doctor changes.
     */
    public function updatedSelectedDoctorId(): void
    {
        $this->visibleCount = 30;
        $this->closeModals();
    }

    /**
     * Show more token rows.
     */
    public function loadMore(): void
    {
        $this->visibleCount += 30;
    }

    /**
     * Open the reserve or arrival modal for the selected token.
     */
    public function selectToken(int $tokenNumber): void
    {
        $token = $this->tokensInRange->get($tokenNumber);

        if ($token === null) {
            $this->viewingTokenNumber = $tokenNumber;
            $this->patientName = '';
            $this->showReserveModal = true;

            return;
        }

        if ($token->status === 'reserved') {
            $this->viewingTokenId = $token->id;
            $this->showArrivalModal = true;
        }
    }

    /**
     * Reserve the selected token for the phone patient.
     */
    public function reserve(): void
    {
        $this->validateOnly('patientName');
        $this->validateOnly('patientPhone');

        if ($this->viewingTokenNumber === null || $this->selectedDoctorId === null) {
            return;
        }

        $service = $this->consultationService;
        $shift = $this->currentShift;

        if ($service === null || $shift === null) {
            Flux::toast(variant: 'danger', text: __('Cannot reserve right now.'));

            return;
        }

        try {
            $queue = app(QueueService::class)->queueFor($service, $this->selectedDoctorId, $shift);

            app(ReservationService::class)->reserve($queue, $this->viewingTokenNumber, $this->patientName, $this->patientPhone);

            $this->closeReserveModal();

            Flux::toast(variant: 'success', text: __('Token :number reserved.', ['number' => $this->viewingTokenNumber]));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    /**
     * Mark the reserved token as arrived and create the invoice.
     */
    public function markArrived(): void
    {
        if ($this->viewingTokenId === null) {
            return;
        }

        $token = QueueToken::find($this->viewingTokenId);

        if ($token === null) {
            Flux::toast(variant: 'danger', text: __('Token not found.'));

            return;
        }

        try {
            $invoice = app(ReservationService::class)->arrive($token);

            app(CreatePrintJob::class)->create($invoice);

            $this->closeArrivalModal();

            Flux::toast(variant: 'success', text: __('Invoice :number created. Print job queued.', ['number' => $invoice->invoice_number]));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    /**
     * Close the reserve modal and reset its state.
     */
    public function closeReserveModal(): void
    {
        $this->showReserveModal = false;
        $this->viewingTokenNumber = null;
        $this->patientName = '';
        $this->patientPhone = '';
        $this->resetValidation();
    }

    /**
     * Close the arrival modal and reset its state.
     */
    public function closeArrivalModal(): void
    {
        $this->showArrivalModal = false;
        $this->viewingTokenId = null;
    }

    /**
     * Close both modals.
     */
    private function closeModals(): void
    {
        $this->closeReserveModal();
        $this->closeArrivalModal();
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
     * Get the consultation service.
     */
    #[Computed]
    public function consultationService(): ?Service
    {
        return Service::whereRaw('LOWER(name) = ?', ['consultation'])->first();
    }

    /**
     * Get the doctors that can perform the consultation service.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function doctors(): Collection
    {
        $service = $this->consultationService;

        if ($service === null) {
            return new Collection();
        }

        if ($service->is_standalone) {
            return Doctor::orderBy('name')->get();
        }

        return Doctor::whereHas('servicePrices', function ($query) use ($service) {
            $query->where('service_id', $service->id);
        })->orderBy('name')->get();
    }

    /**
     * Get the current open queue for the selected doctor and consultation service.
     */
    #[Computed]
    public function currentQueue(): ?ServiceQueue
    {
        $service = $this->consultationService;
        $shift = $this->currentShift;

        if ($service === null || $shift === null || $this->selectedDoctorId === null) {
            return null;
        }

        $query = ServiceQueue::where('service_id', $service->id)
            ->where('doctor_id', $this->selectedDoctorId)
            ->where('status', 'open');

        return match ($service->token_reset_type->value) {
            'shift' => $query->where('shift_id', $shift->id)->first(),
            default => $query->whereDate('date', $shift->opened_at)->first(),
        };
    }

    /**
     * Get the tokens within the currently visible range for the current queue.
     *
     * @return \Illuminate\Support\Collection<int, QueueToken>
     */
    #[Computed]
    public function tokensInRange(): \Illuminate\Support\Collection
    {
        $queue = $this->currentQueue;

        if ($queue === null) {
            return collect();
        }

        return QueueToken::with('patient')
            ->where('service_queue_id', $queue->id)
            ->whereBetween('token_number', [1, $this->visibleCount])
            ->get()
            ->keyBy('token_number');
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Reservations') }}</flux:heading>
        </div>

        @if (! $this->currentShift)
            <flux:card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('No Open Shift') }}</flux:heading>
                        <flux:text class="text-zinc-500">{{ __('Open a shift to manage reservations.') }}</flux:text>
                    </div>
                    <flux:button variant="primary" icon="lock-open" :href="route('reception.shift')" wire:navigate>
                        {{ __('Open Shift') }}
                    </flux:button>
                </div>
            </flux:card>
        @elseif ($this->consultationService === null)
            <flux:card>
                <flux:heading level="2">{{ __('Consultation Service Missing') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __('Please create a service named "consultation" in Management first.') }}
                </flux:text>
            </flux:card>
        @else
            <flux:card>
                <div class="grid grid-cols-1 items-end gap-6 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Service') }}</flux:label>
                        <flux:input type="text" value="{{ $this->consultationService->name }}" disabled />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Doctor') }}</flux:label>
                        <flux:select wire:model.live="selectedDoctorId" required>
                            <option value="">{{ __('Select a doctor') }}</option>
                            @foreach ($this->doctors as $doctor)
                                <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="selectedDoctorId" />
                    </flux:field>
                </div>
            </flux:card>

            @if ($this->selectedDoctorId)
                <flux:card>
                    <div class="mb-4 flex items-center justify-between">
                        <flux:heading level="2">{{ __('Tokens') }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Showing :count tokens', ['count' => $this->visibleCount]) }}
                        </flux:text>
                    </div>

                    <div class="grid grid-cols-5 gap-3">
                        @for ($number = 1; $number <= $this->visibleCount; $number++)
                            @php
                                $token = $this->tokensInRange->get($number);
                                $isReserved = $token !== null && $token->status === 'reserved';
                                $isUsed = $token !== null && ! $isReserved;
                            @endphp

                            <button
                                type="button"
                                wire:click="selectToken({{ $number }})"
                                wire:key="token-{{ $number }}"
                                @disabled($isUsed)
                                class="relative flex flex-col items-center justify-center rounded-lg border p-4 transition-colors
                                    @if ($isUsed) bg-zinc-100 text-zinc-400 cursor-not-allowed dark:bg-zinc-800 dark:text-zinc-500
                                    @elseif ($isReserved) bg-amber-50 text-amber-700 border-amber-200 hover:bg-amber-100 dark:bg-amber-900/20 dark:text-amber-400 dark:border-amber-800
                                    @else bg-white text-zinc-700 border-zinc-200 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-200 dark:border-zinc-700 @endif"
                            >
                                <span class="text-lg font-semibold">{{ $number }}</span>
                                @if ($token?->patient)
                                    <span class="mt-1 dark:text-zinc-300 max-w-full truncate text-xs">{{ $token->patient->name }}</span>
                                @elseif ($isUsed)
                                    <span class="mt-1 text-xs">{{ __('Used') }}</span>
                                @endif
                            </button>
                        @endfor
                    </div>

                    <div class="mt-6 flex justify-center">
                        <flux:button type="button" variant="outline" wire:click="loadMore">
                            {{ __('Load more tokens') }}
                        </flux:button>
                    </div>
                </flux:card>
            @endif
        @endif
    </div>

    <flux:modal wire:model="showReserveModal" class="w-full max-w-sm">
        <flux:heading level="2">
            {{ __('Reserve Token :number', ['number' => $viewingTokenNumber]) }}
        </flux:heading>

        <form wire:submit="reserve" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Patient name') }}</flux:label>
                <flux:input wire:model="patientName" type="text" required placeholder="Patient Name..." />
                <flux:error name="patientName" />
            </flux:field>

            <div x-data="{ phone: '' }">
                <flux:field>
                    <flux:label>{{ __('Phone number') }}</flux:label>
                    <flux:input
                        type="tel"
                        inputmode="numeric"
                        maxlength="11"
                        pattern="[0-9]{11}"
                        required
                        placeholder="03XXXXXXXXX"
                        x-model="phone"
                        x-init="phone = $wire.patientPhone"
                        x-on:input="phone = phone.replace(/\D/g, ''); $wire.patientPhone = phone"
                        x-bind:class="phone.length === 11 ? 'ring-2 ring-green-500 dark:ring-green-400 border-green-500 dark:border-green-400' : ''"
                    />
                    <flux:error name="patientPhone" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeReserveModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Reserve') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showArrivalModal" class="w-full max-w-sm">
        @php
            $arrivalToken = $viewingTokenId ? \App\Models\QueueToken::with('patient')->find($viewingTokenId) : null;
        @endphp

        <flux:heading level="2">
            {{ __('Token :number', ['number' => $arrivalToken?->token_number]) }}
        </flux:heading>

        @if ($arrivalToken)
            <div class="mt-4 space-y-2 text-sm">
                <flux:text>
                    <span class="text-zinc-500">{{ __('Patient') }}:</span> {{ $arrivalToken->patient?->name }}
                </flux:text>
                <flux:text>
                    <span class="text-zinc-500">{{ __('Status') }}:</span> {{ __(ucfirst($arrivalToken->status)) }}
                </flux:text>
            </div>
        @endif

        <div class="mt-6 flex justify-end gap-3">
            <flux:button type="button" variant="ghost" wire:click="closeArrivalModal">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="button" variant="primary" wire:click="markArrived">
                {{ __('Mark arrived') }}
            </flux:button>
        </div>
    </flux:modal>
</div>
