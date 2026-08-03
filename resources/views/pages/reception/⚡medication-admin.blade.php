<?php

use App\Enums\MedicationOrderStatus;
use App\Models\MedicationOrder;
use App\Models\Shift;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Medication Admin')] class extends Component
{
    public ?int $selectedOrderId = null;

    /**
     * Pending medication orders for the current open shift.
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
                'drips.additives',
            ])
            ->where('status', MedicationOrderStatus::Pending)
            ->whereHas('queueToken.serviceQueue', function ($query) use ($shift): void {
                $query->where('shift_id', $shift->id);
            })
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The order currently being reviewed.
     */
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
                'drips.additives',
            ])->find($this->selectedOrderId);
    }

    /**
     * Select an order for review.
     */
    public function selectOrder(int $orderId): void
    {
        $order = $this->orders->firstWhere('id', $orderId);

        if ($order === null) {
            Flux::toast(variant: 'danger', text: __('Order is no longer pending.'));

            return;
        }

        $this->selectedOrderId = $orderId;
    }

    /**
     * Return to the pending list.
     */
    public function backToList(): void
    {
        $this->selectedOrderId = null;
    }

    /**
     * Mark the selected order as administered.
     */
    public function markAdministered(): void
    {
        $order = $this->selectedOrder;

        if ($order === null || $order->status !== MedicationOrderStatus::Pending) {
            Flux::toast(variant: 'danger', text: __('Order is no longer pending.'));
            $this->backToList();

            return;
        }

        $order->update([
            'status' => MedicationOrderStatus::Administered,
            'administered_by' => auth()->id(),
            'administered_at' => now(),
        ]);

        unset($this->orders, $this->selectedOrder);

        Flux::toast(variant: 'success', text: __('Medication marked as administered.'));
        $this->backToList();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between gap-3">
        <flux:heading level="1">{{ __('Medication Admin') }}</flux:heading>
        @if ($selectedOrderId === null)
            <flux:badge color="zinc" size="lg">{{ $this->orders->count() }}</flux:badge>
        @endif
    </div>

    @if ($selectedOrderId === null)
        <div class="flex flex-1 flex-col gap-2" wire:poll.20s>
            @forelse ($this->orders as $order)
                <button
                    type="button"
                    wire:key="medication-order-{{ $order->id }}"
                    wire:click="selectOrder({{ $order->id }})"
                    class="flex w-full items-center gap-4 rounded-xl border border-zinc-200 bg-white px-4 py-4 text-left shadow-sm transition active:scale-[0.99] dark:border-zinc-700 dark:bg-zinc-800"
                >
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-xl font-bold text-white dark:bg-white dark:text-zinc-900">
                        {{ $order->queueToken?->token_number }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-lg font-semibold text-zinc-900 dark:text-white">
                            {{ $order->patient?->name ?? __('Unknown') }}
                        </span>
                        <span class="mt-0.5 block truncate text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $order->queueToken?->serviceQueue?->service?->name }}
                            @if ($order->doctor)
                                · {{ $order->doctor->name }}
                            @endif
                        </span>
                    </span>
                    <flux:icon name="chevron-right" class="size-5 shrink-0 text-zinc-400" />
                </button>
            @empty
                <div class="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-600">
                    <flux:icon name="clipboard-document-check" class="size-10 text-zinc-400" />
                    <p class="text-base font-medium text-zinc-700 dark:text-zinc-200">{{ __('No pending medication orders') }}</p>
                    <p class="text-sm text-zinc-500">{{ __('Orders from doctors will appear here for administration.') }}</p>
                </div>
            @endforelse
        </div>
    @else
        @php($order = $this->selectedOrder)
        <div class="sticky top-0 z-10 -mx-4 border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900 sm:mx-0 sm:rounded-xl sm:border">
            <div class="flex items-center gap-3">
                <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-lg font-bold text-white dark:bg-white dark:text-zinc-900">
                    {{ $order?->queueToken?->token_number }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-lg font-semibold text-zinc-900 dark:text-white">
                        {{ $order?->patient?->name ?? __('Unknown') }}
                    </p>
                    <p class="truncate text-sm text-zinc-500">
                        {{ $order?->queueToken?->serviceQueue?->service?->name }}
                        @if ($order?->doctor)
                            · {{ $order->doctor->name }}
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="sm" class="mb-2">{{ __('Medicines') }}</flux:heading>
                @forelse ($order?->medicines ?? [] as $medicine)
                    <p class="text-sm text-zinc-700 dark:text-zinc-200">
                        {{ $medicine->name }}
                        <span class="text-zinc-500">— {{ $medicine->dose->label() }} · {{ $medicine->days }} {{ __('days') }}</span>
                    </p>
                @empty
                    <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                @endforelse
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="sm" class="mb-2">{{ __('Injections') }}</flux:heading>
                @forelse ($order?->injections ?? [] as $injection)
                    <p class="text-sm text-zinc-700 dark:text-zinc-200">
                        {{ $injection->name }}
                        <span class="text-zinc-500">
                            — {{ $injection->administration_type->label() }}
                            @if ($injection->volume_ml !== null)
                                · {{ rtrim(rtrim(number_format($injection->volume_ml, 2), '0'), '.') }} ml
                            @endif
                        </span>
                    </p>
                @empty
                    <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                @endforelse
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="sm" class="mb-2">{{ __('Drips') }}</flux:heading>
                @forelse ($order?->drips ?? [] as $drip)
                    <div class="mb-2 last:mb-0">
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                            {{ $drip->name }} — {{ rtrim(rtrim(number_format($drip->volume_ml, 2), '0'), '.') }} ml
                        </p>
                        @foreach ($drip->additives as $additive)
                            <p class="ms-3 text-sm text-zinc-500">
                                + {{ rtrim(rtrim(number_format($additive->volume_ml, 2), '0'), '.') }} ml {{ $additive->name }}
                            </p>
                        @endforeach
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                @endforelse
            </div>

            @if ($order?->notes)
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="sm" class="mb-2">{{ __('Notes') }}</flux:heading>
                    <p class="text-sm text-zinc-700 dark:text-zinc-200">{{ $order->notes }}</p>
                </div>
            @endif
        </div>

        <div class="mt-auto flex flex-col gap-3 pt-4">
            <flux:button
                type="button"
                variant="primary"
                class="h-12 w-full text-base font-semibold"
                wire:click="markAdministered"
                wire:confirm="{{ __('Mark this medication order as administered?') }}"
            >
                {{ __('Mark administered') }}
            </flux:button>
            <flux:button type="button" variant="ghost" wire:click="backToList" class="w-full">
                {{ __('Back to list') }}
            </flux:button>
        </div>
    @endif
</div>
