<?php

use App\Enums\MedicationOrderStatus;
use App\Models\MedicationOrder;
use App\Models\Shift;
use App\Services\HealthAidePinSession;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.display')] #[Title('Medication Delivery')] class extends Component
{
    public ?int $selectedOrderId = null;

    /** @var list<int> */
    public array $selectedMedicineIds = [];

    /** @var list<int> */
    public array $selectedInjectionIds = [];

    public string $pin = '';

    public bool $showPinModal = false;

    public ?string $pendingAction = null;

    public function mount(HealthAidePinSession $pinSession): void
    {
        if (! $pinSession->check()) {
            $this->showPinModal = true;
        }
    }

    /**
     * Pending orders with undelivered medicines or injections for the open shift.
     *
     * @return Collection<int, MedicationOrder>
     */
    #[Computed]
    public function orders(): Collection
    {
        $shift = Shift::current();

        if ($shift === null) {
            return new Collection;
        }

        return MedicationOrder::query()
            ->with([
                'patient',
                'doctor',
                'queueToken.serviceQueue.service',
                'medicines',
                'injections',
            ])
            ->where('status', MedicationOrderStatus::Pending)
            ->where(function ($query): void {
                $query->whereHas('medicines', fn ($q) => $q->whereNull('delivered_at'))
                    ->orWhereHas('injections', fn ($q) => $q->whereNull('delivered_at'));
            })
            ->whereHas('queueToken.serviceQueue', function ($query) use ($shift): void {
                $query->where('shift_id', $shift->id);
            })
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function selectedOrder(): ?MedicationOrder
    {
        if ($this->selectedOrderId === null) {
            return null;
        }

        return $this->orders->firstWhere('id', $this->selectedOrderId)
            ?? MedicationOrder::with([
                'patient',
                'doctor',
                'queueToken.serviceQueue.service',
                'medicines',
                'injections',
            ])->find($this->selectedOrderId);
    }

    #[Computed]
    public function currentAideName(): ?string
    {
        return app(HealthAidePinSession::class)->current()?->name;
    }

    public function selectOrder(int $orderId): void
    {
        $order = $this->orders->firstWhere('id', $orderId);

        if ($order === null) {
            Flux::toast(variant: 'danger', text: __('Order is no longer pending.'));

            return;
        }

        $this->selectedOrderId = $orderId;
        $this->selectedMedicineIds = [];
        $this->selectedInjectionIds = [];
    }

    public function backToList(): void
    {
        $this->selectedOrderId = null;
        $this->selectedMedicineIds = [];
        $this->selectedInjectionIds = [];
    }

    public function requestNext(): void
    {
        if ($this->selectedOrder === null) {
            return;
        }

        if ($this->selectedMedicineIds === [] && $this->selectedInjectionIds === []) {
            Flux::toast(variant: 'danger', text: __('Select at least one medicine or injection to deliver.'));

            return;
        }

        $this->requirePinThen('deliverNext');
    }

    public function verifyPin(HealthAidePinSession $pinSession): void
    {
        $this->validate([
            'pin' => ['required', 'digits_between:4,6'],
        ]);

        $aide = $pinSession->attempt($this->pin);

        if ($aide === null) {
            $this->addError('pin', __('Invalid PIN.'));

            return;
        }

        $this->pin = '';
        $this->showPinModal = false;
        $this->resetValidation();

        $action = $this->pendingAction;
        $this->pendingAction = null;

        if ($action === 'deliverNext') {
            $this->deliverNext();
        }
    }

    public function lock(HealthAidePinSession $pinSession): void
    {
        $pinSession->forget();
        $this->showPinModal = true;
        $this->pendingAction = null;
        Flux::toast(text: __('Session locked.'));
    }

    public function deliverNext(): void
    {
        $aide = app(HealthAidePinSession::class)->current();

        if ($aide === null) {
            $this->requirePinThen('deliverNext');

            return;
        }

        $order = $this->selectedOrder;

        if ($order === null || $order->status !== MedicationOrderStatus::Pending) {
            Flux::toast(variant: 'danger', text: __('Order is no longer pending.'));
            $this->backToList();
            unset($this->orders, $this->selectedOrder);

            return;
        }

        DB::transaction(function () use ($order, $aide): void {
            $order->medicines()
                ->whereIn('id', $this->selectedMedicineIds)
                ->whereNull('delivered_at')
                ->update([
                    'delivered_at' => now(),
                    'delivered_by_health_aide_id' => $aide->id,
                ]);

            $order->injections()
                ->whereIn('id', $this->selectedInjectionIds)
                ->whereNull('delivered_at')
                ->update([
                    'delivered_at' => now(),
                    'delivered_by_health_aide_id' => $aide->id,
                ]);

            $order->refresh();
            $order->markAdministeredByHealthAide($aide);
        });

        unset($this->orders, $this->selectedOrder);

        Flux::toast(variant: 'success', text: __('Delivery saved.'));

        $next = $this->orders->first(fn (MedicationOrder $candidate): bool => $candidate->id !== $order->id)
            ?? $this->orders->first();

        if ($next === null) {
            $this->backToList();
            Flux::toast(text: __('No more pending prescriptions.'));

            return;
        }

        $this->selectOrder($next->id);
    }

    protected function requirePinThen(string $action): void
    {
        if (app(HealthAidePinSession::class)->check()) {
            if ($action === 'deliverNext') {
                $this->deliverNext();
            }

            return;
        }

        $this->pendingAction = $action;
        $this->pin = '';
        $this->showPinModal = true;
        $this->resetValidation();
    }
}; ?>

<div class="paper-slip-board flex min-h-screen flex-col bg-zinc-950 text-white" wire:poll.30s>
    <div class="flex items-center justify-between gap-3 border-b border-zinc-800 px-4 py-3">
        <div>
            <flux:heading level="1" size="lg">{{ __('Medication Delivery') }}</flux:heading>
            @if ($this->currentAideName)
                <flux:text class="text-zinc-400">{{ __('Signed in as') }} {{ $this->currentAideName }}</flux:text>
            @endif
        </div>
        <flux:button type="button" variant="ghost" icon="lock-closed" wire:click="lock">
            {{ __('Lock') }}
        </flux:button>
    </div>

    <div class="flex flex-1 flex-col gap-4 p-4">
        @if ($selectedOrderId === null)
            <div class="flex items-center justify-between">
                <flux:heading level="2" size="md">{{ __('Pending prescriptions') }}</flux:heading>
                <flux:badge color="zinc" size="lg">{{ $this->orders->count() }}</flux:badge>
            </div>

            <div class="grid flex-1 grid-cols-1 content-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @forelse ($this->orders as $order)
                    @php
                        $pendingMedicines = $order->medicines->whereNull('delivered_at')->count();
                        $pendingInjections = $order->injections->whereNull('delivered_at')->count();
                    @endphp
                    <x-paper-slip
                        as="button"
                        type="button"
                        :token="$order->queueToken?->token_number"
                        wire:key="med-delivery-order-{{ $order->id }}"
                        wire:click="selectOrder({{ $order->id }})"
                        class="active:scale-[0.99] hover:-translate-y-0.5 hover:shadow-[0_1px_0_rgba(255,255,255,0.85)_inset,0_4px_8px_rgba(0,0,0,0.08),0_16px_28px_rgba(0,0,0,0.14)]"
                    >
                        <p class="truncate text-base font-semibold text-zinc-900">
                            {{ $order->patient?->name ?? __('Unknown') }}
                        </p>
                        <p class="truncate text-xs uppercase tracking-wide text-zinc-500">
                            {{ $order->patient?->mrn ?? __('No MRN') }}
                            · {{ $order->queueToken?->serviceQueue?->service?->name }}
                        </p>
                        <div class="mt-1 flex flex-wrap gap-2 border-t border-dashed border-zinc-400/70 pt-2 text-xs text-zinc-600">
                            @if ($pendingMedicines > 0)
                                <span>{{ $pendingMedicines }} {{ __('Medicines') }}</span>
                            @endif
                            @if ($pendingInjections > 0)
                                <span>{{ $pendingInjections }} {{ __('Injections') }}</span>
                            @endif
                        </div>
                        <p class="mt-auto pt-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-400">
                            {{ __('Tap to deliver') }}
                        </p>
                    </x-paper-slip>
                @empty
                    <div class="col-span-full flex flex-1 flex-col items-center justify-center gap-2 rounded-sm border border-dashed border-zinc-700 px-6 py-16 text-center">
                        <flux:icon name="clipboard-document-check" class="size-10 text-zinc-500" />
                        <p class="text-base font-medium">{{ __('No pending prescriptions') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('Medicine and injection orders will appear here.') }}</p>
                    </div>
                @endforelse
            </div>
        @else
            @php($order = $this->selectedOrder)
            <x-paper-slip
                :token="$order?->queueToken?->token_number"
                class="mx-auto w-full max-w-lg"
            >
                <div class="space-y-1">
                    <p class="truncate text-lg font-semibold text-zinc-900">{{ $order?->patient?->name ?? __('Unknown') }}</p>
                    <p class="truncate text-xs uppercase tracking-wide text-zinc-500">
                        {{ $order?->patient?->mrn ?? __('No MRN') }}
                        · {{ $order?->queueToken?->serviceQueue?->service?->name }}
                    </p>
                </div>

                <div class="space-y-4 border-t border-dashed border-zinc-400/70 pt-3">
                    <div>
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Medicines') }}</p>
                        @forelse ($order?->medicines ?? [] as $medicine)
                            @if ($medicine->delivered_at)
                                <p class="mb-2 text-sm text-zinc-400 line-through">
                                    {{ $medicine->name }} — {{ $medicine->dose->label() }} · {{ $medicine->days }} {{ __('days') }}
                                    <span class="ms-2 no-underline">{{ __('Delivered') }}</span>
                                </p>
                            @else
                                <label wire:key="med-line-{{ $medicine->id }}" class="mb-2 flex cursor-pointer items-start gap-3 text-sm text-zinc-800">
                                    <input
                                        type="checkbox"
                                        value="{{ $medicine->id }}"
                                        wire:model="selectedMedicineIds"
                                        class="mt-0.5 size-5 rounded border-zinc-400 bg-white text-zinc-900"
                                    >
                                    <span>
                                        {{ $medicine->name }}
                                        <span class="text-zinc-500">— {{ $medicine->dose->label() }} · {{ $medicine->days }} {{ __('days') }}</span>
                                    </span>
                                </label>
                            @endif
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @endforelse
                    </div>

                    <div class="border-t border-dashed border-zinc-400/70 pt-3">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Injections') }}</p>
                        @forelse ($order?->injections ?? [] as $injection)
                            @if ($injection->delivered_at)
                                <p class="mb-2 text-sm text-zinc-400 line-through">
                                    {{ $injection->name }} — {{ $injection->administration_type->label() }}
                                    <span class="ms-2 no-underline">{{ __('Delivered') }}</span>
                                </p>
                            @else
                                <label wire:key="inj-line-{{ $injection->id }}" class="mb-2 flex cursor-pointer items-start gap-3 text-sm text-zinc-800">
                                    <input
                                        type="checkbox"
                                        value="{{ $injection->id }}"
                                        wire:model="selectedInjectionIds"
                                        class="mt-0.5 size-5 rounded border-zinc-400 bg-white text-zinc-900"
                                    >
                                    <span>
                                        {{ $injection->name }}
                                        <span class="text-zinc-500">
                                            — {{ $injection->administration_type->label() }}
                                            @if ($injection->volume_ml !== null)
                                                · {{ rtrim(rtrim(number_format($injection->volume_ml, 2), '0'), '.') }} ml
                                            @endif
                                        </span>
                                    </span>
                                </label>
                            @endif
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @endforelse
                    </div>
                </div>

                <x-slot:footer>
                    <div class="flex flex-col gap-3">
                        <flux:button
                            type="button"
                            variant="primary"
                            class="h-12 w-full text-base font-semibold"
                            wire:click="requestNext"
                        >
                            {{ __('Next') }}
                        </flux:button>
                        <flux:button type="button" variant="ghost" wire:click="backToList" class="w-full">
                            {{ __('Back to list') }}
                        </flux:button>
                    </div>
                </x-slot:footer>
            </x-paper-slip>
        @endif
    </div>

    @if ($showPinModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/95 p-4">
            <div class="w-full max-w-sm rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-xl">
                <flux:heading level="2" size="lg" class="text-center">
                    {{ __('Enter PIN') }}
                </flux:heading>
                <flux:text class="mt-2 text-center text-zinc-500">
                    {{ __('Enter your health aide PIN to continue. Session lasts 10 minutes.') }}
                </flux:text>

                <form wire:submit="verifyPin" class="mt-6 space-y-4">
                    <flux:input
                        type="password"
                        wire:model="pin"
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="----"
                        class="text-center text-2xl tracking-[0.5em]"
                        autofocus
                    />
                    @error('pin')
                        <flux:text variant="danger" class="text-center">{{ $message }}</flux:text>
                    @enderror
                    <flux:button type="submit" variant="primary" class="w-full">
                        {{ __('Unlock') }}
                    </flux:button>
                </form>
            </div>
        </div>
    @endif
</div>
