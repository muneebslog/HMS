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

<div class="flex min-h-screen flex-col bg-zinc-950 text-white" wire:poll.30s>
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

            <div class="flex flex-1 flex-col gap-2">
                @forelse ($this->orders as $order)
                    <button
                        type="button"
                        wire:key="med-delivery-order-{{ $order->id }}"
                        wire:click="selectOrder({{ $order->id }})"
                        class="flex w-full items-center gap-4 rounded-xl border border-zinc-800 bg-zinc-900 px-4 py-4 text-left transition active:scale-[0.99]"
                    >
                        <span class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-white text-xl font-bold text-zinc-900">
                            {{ $order->queueToken?->token_number }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-lg font-semibold">
                                {{ $order->patient?->name ?? __('Unknown') }}
                            </span>
                            <span class="mt-0.5 block truncate text-sm text-zinc-400">
                                {{ $order->patient?->mrn ?? __('No MRN') }}
                                · {{ $order->queueToken?->serviceQueue?->service?->name }}
                            </span>
                        </span>
                        <flux:icon name="chevron-right" class="size-5 shrink-0 text-zinc-500" />
                    </button>
                @empty
                    <div class="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-700 px-6 py-16 text-center">
                        <flux:icon name="clipboard-document-check" class="size-10 text-zinc-500" />
                        <p class="text-base font-medium">{{ __('No pending prescriptions') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('Medicine and injection orders will appear here.') }}</p>
                    </div>
                @endforelse
            </div>
        @else
            @php($order = $this->selectedOrder)
            <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
                <div class="flex items-center gap-3">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-white text-lg font-bold text-zinc-900">
                        {{ $order?->queueToken?->token_number }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-lg font-semibold">{{ $order?->patient?->name ?? __('Unknown') }}</p>
                        <p class="truncate text-sm text-zinc-400">
                            {{ $order?->patient?->mrn ?? __('No MRN') }}
                            · {{ $order?->queueToken?->serviceQueue?->service?->name }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
                    <flux:heading size="sm" class="mb-3">{{ __('Medicines') }}</flux:heading>
                    @forelse ($order?->medicines ?? [] as $medicine)
                        @if ($medicine->delivered_at)
                            <p class="mb-2 text-sm text-zinc-500 line-through">
                                {{ $medicine->name }} — {{ $medicine->dose->label() }} · {{ $medicine->days }} {{ __('days') }}
                                <span class="ms-2 no-underline">{{ __('Delivered') }}</span>
                            </p>
                        @else
                            <label wire:key="med-line-{{ $medicine->id }}" class="mb-2 flex cursor-pointer items-start gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    value="{{ $medicine->id }}"
                                    wire:model="selectedMedicineIds"
                                    class="mt-0.5 size-5 rounded border-zinc-600 bg-zinc-800 text-sky-500"
                                >
                                <span>
                                    {{ $medicine->name }}
                                    <span class="text-zinc-400">— {{ $medicine->dose->label() }} · {{ $medicine->days }} {{ __('days') }}</span>
                                </span>
                            </label>
                        @endif
                    @empty
                        <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                    @endforelse
                </div>

                <div class="rounded-xl border border-zinc-800 bg-zinc-900 p-4">
                    <flux:heading size="sm" class="mb-3">{{ __('Injections') }}</flux:heading>
                    @forelse ($order?->injections ?? [] as $injection)
                        @if ($injection->delivered_at)
                            <p class="mb-2 text-sm text-zinc-500 line-through">
                                {{ $injection->name }} — {{ $injection->administration_type->label() }}
                                <span class="ms-2 no-underline">{{ __('Delivered') }}</span>
                            </p>
                        @else
                            <label wire:key="inj-line-{{ $injection->id }}" class="mb-2 flex cursor-pointer items-start gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    value="{{ $injection->id }}"
                                    wire:model="selectedInjectionIds"
                                    class="mt-0.5 size-5 rounded border-zinc-600 bg-zinc-800 text-sky-500"
                                >
                                <span>
                                    {{ $injection->name }}
                                    <span class="text-zinc-400">
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

            <div class="mt-auto flex flex-col gap-3 pt-4">
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
