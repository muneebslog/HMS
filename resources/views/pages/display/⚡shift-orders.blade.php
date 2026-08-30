<?php

use App\Enums\DripLineStatus;
use App\Enums\MedicationOrderStatus;
use App\Models\MedicationOrder;
use App\Models\MedicationOrderDrip;
use App\Models\MedicationOrderInjection;
use App\Models\MedicationOrderMedicine;
use App\Models\Shift;
use App\Services\ShiftOrdersExportService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.display')] #[Title('Shift Orders')] class extends Component
{
    #[Url]
    public ?int $shiftId = null;

    public ?int $selectedOrderId = null;

    public bool $showExportModal = false;

    public string $exportType = ShiftOrdersExportService::TYPE_ALL;

    public function mount(): void
    {
        if ($this->shiftId !== null && Shift::query()->whereKey($this->shiftId)->exists()) {
            return;
        }

        $this->shiftId = Shift::current()?->id
            ?? Shift::query()->latest('opened_at')->value('id');
    }

    #[Computed]
    public function shift(): ?Shift
    {
        if ($this->shiftId === null) {
            return null;
        }

        return Shift::query()->find($this->shiftId);
    }

    #[Computed]
    public function previousShiftId(): ?int
    {
        $shift = $this->shift;

        if ($shift === null) {
            return null;
        }

        return Shift::query()
            ->where('opened_at', '<', $shift->opened_at)
            ->latest('opened_at')
            ->value('id');
    }

    #[Computed]
    public function nextShiftId(): ?int
    {
        $shift = $this->shift;

        if ($shift === null) {
            return null;
        }

        return Shift::query()
            ->where('opened_at', '>', $shift->opened_at)
            ->oldest('opened_at')
            ->value('id');
    }

    /**
     * Medication and drip orders for the selected shift.
     *
     * @return Collection<int, MedicationOrder>
     */
    #[Computed]
    public function orders(): Collection
    {
        $shift = $this->shift;

        if ($shift === null) {
            return collect();
        }

        return MedicationOrder::query()
            ->with([
                'patient',
                'queueToken.serviceQueue.service',
                'medicines',
                'injections',
                'drips.additives',
                'dripCharges',
            ])
            ->where('status', '!=', MedicationOrderStatus::Draft)
            ->whereHas('queueToken.serviceQueue', function ($query) use ($shift): void {
                $query->forShift($shift);
            })
            ->where(function ($query): void {
                $query->whereHas('medicines')
                    ->orWhereHas('injections')
                    ->orWhereHas('drips');
            })
            ->get()
            ->sortByDesc(fn (MedicationOrder $order): int => $order->queueToken?->token_number ?? 0)
            ->values();
    }

    #[Computed]
    public function selectedOrder(): ?MedicationOrder
    {
        if ($this->selectedOrderId === null) {
            return null;
        }

        return $this->orders->firstWhere('id', $this->selectedOrderId);
    }

    public function selectOrder(int $orderId): void
    {
        $this->selectedOrderId = $orderId;
    }

    public function backToList(): void
    {
        $this->selectedOrderId = null;
    }

    public function goToPreviousShift(): void
    {
        if ($this->previousShiftId === null) {
            return;
        }

        $this->shiftId = $this->previousShiftId;
        $this->selectedOrderId = null;
        $this->forgetShiftBoard();
    }

    public function goToNextShift(): void
    {
        if ($this->nextShiftId === null) {
            return;
        }

        $this->shiftId = $this->nextShiftId;
        $this->selectedOrderId = null;
        $this->forgetShiftBoard();
    }

    public function openExportModal(): void
    {
        $this->exportType = ShiftOrdersExportService::TYPE_ALL;
        $this->showExportModal = true;
    }

    public function closeExportModal(): void
    {
        $this->showExportModal = false;
    }

    public function exportUrl(): ?string
    {
        if ($this->shiftId === null) {
            return null;
        }

        if (! in_array($this->exportType, ShiftOrdersExportService::types(), true)) {
            return null;
        }

        return route('display.shift_orders.export', [
            'shiftId' => $this->shiftId,
            'type' => $this->exportType,
        ]);
    }

    private function forgetShiftBoard(): void
    {
        unset($this->shift, $this->previousShiftId, $this->nextShiftId, $this->orders, $this->selectedOrder);
    }

    /**
     * @return 'given'|'waiting'|'not_given'
     */
    public function medicineStatus(MedicationOrder $order, MedicationOrderMedicine $line): string
    {
        return $this->deliveryLineStatus($order, $line->isDelivered());
    }

    /**
     * @return 'given'|'waiting'|'not_given'
     */
    public function injectionStatus(MedicationOrder $order, MedicationOrderInjection $line): string
    {
        return $this->deliveryLineStatus($order, $line->isDelivered());
    }

    /**
     * @return 'given'|'waiting'|'not_given'
     */
    public function dripStatus(MedicationOrderDrip $drip): string
    {
        return match ($drip->status) {
            DripLineStatus::Done => 'given',
            DripLineStatus::Started => 'waiting',
            default => 'not_given',
        };
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'given' => __('Given'),
            'waiting' => __('Waiting'),
            default => __('Not given'),
        };
    }

    /**
     * @return 'given'|'waiting'|'not_given'
     */
    private function deliveryLineStatus(MedicationOrder $order, bool $given): string
    {
        if ($given) {
            return 'given';
        }

        if ($order->erHoldReason() !== null) {
            return 'waiting';
        }

        return 'not_given';
    }
};
?>

<div class="paper-slip-board flex min-h-screen flex-col bg-zinc-950 text-white" wire:poll.10s>
    <div class="flex flex-col gap-3 border-b border-zinc-800 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading level="1" size="lg">{{ __('Shift Orders') }}</flux:heading>
            @if ($this->shift)
                <flux:text class="text-zinc-400">
                    {{ $this->shift->opened_at->format('Y-m-d H:i') }}
                    @if ($this->shift->status === 'open')
                        · {{ __('Current shift') }}
                    @else
                        · {{ __('Closed') }}
                    @endif
                </flux:text>
            @else
                <flux:text class="text-zinc-400">{{ __('No shifts yet') }}</flux:text>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                type="button"
                variant="ghost"
                icon="arrow-down-tray"
                wire:click="openExportModal"
                :disabled="$this->shift === null"
            >
                {{ __('Export') }}
            </flux:button>
            <flux:button
                type="button"
                variant="ghost"
                icon="chevron-left"
                wire:click="goToPreviousShift"
                :disabled="$this->previousShiftId === null"
            >
                {{ __('Previous') }}
            </flux:button>
            <flux:button
                type="button"
                variant="ghost"
                icon:trailing="chevron-right"
                wire:click="goToNextShift"
                :disabled="$this->nextShiftId === null"
            >
                {{ __('Next') }}
            </flux:button>
        </div>
    </div>

    <div class="flex flex-1 flex-col gap-4 p-4">
        @if ($this->selectedOrder)
            @php($order = $this->selectedOrder)
            <x-paper-slip
                :token="$order->queueToken?->token_number"
                class="mx-auto w-full max-w-lg"
            >
                <div class="space-y-1">
                    <p class="truncate text-lg font-semibold text-zinc-900">{{ $order->patient?->name ?? __('Unknown') }}</p>
                    <p class="truncate text-xs uppercase tracking-wide text-zinc-500">
                        {{ $order->patient?->mrn ?? __('No MRN') }}
                        · {{ $order->queueToken?->serviceQueue?->service?->name }}
                    </p>
                </div>

                <div class="space-y-4 border-t border-dashed border-zinc-400/70 pt-3">
                    <div>
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Medicines') }}</p>
                        @if ($order->medicines->isEmpty())
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @else
                            @foreach ($order->sortedMedicines() as $medicine)
                                @php($status = $this->medicineStatus($order, $medicine))
                                <div wire:key="shift-med-{{ $medicine->id }}" class="mb-2 flex items-start justify-between gap-3">
                                    <x-medicine-line
                                        :name="$medicine->name"
                                        :detail="collect([$medicine->dose->label(), $medicine->comment])->filter()->implode(' · ')"
                                        :is-syrup="$medicine->isSyrup()"
                                        :delivered="$status === 'given'"
                                        class="mb-0 min-w-0 flex-1"
                                    />
                                    <x-shift-order-status :status="$status" :label="$this->statusLabel($status)" />
                                </div>
                            @endforeach
                        @endif
                    </div>

                    <div class="border-t border-dashed border-zinc-400/70 pt-3">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Injections') }}</p>
                        @if ($order->injections->isEmpty())
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @else
                            @foreach ($order->injections as $injection)
                                @php($status = $this->injectionStatus($order, $injection))
                                <div wire:key="shift-inj-{{ $injection->id }}" class="mb-2 flex items-start justify-between gap-3">
                                    <p @class(['text-sm text-zinc-800', 'text-zinc-400 line-through' => $status === 'given'])>
                                        {{ $injection->name }}
                                        <span class="text-zinc-500 no-underline">
                                            — {{ $injection->administration_type->label() }}
                                            @if (filled($injection->comment))
                                                · {{ $injection->comment }}
                                            @endif
                                        </span>
                                    </p>
                                    <x-shift-order-status :status="$status" :label="$this->statusLabel($status)" />
                                </div>
                            @endforeach
                        @endif
                    </div>

                    <div class="border-t border-dashed border-zinc-400/70 pt-3">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Drips') }}</p>
                        @if ($order->drips->isEmpty())
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @else
                            @foreach ($order->drips as $drip)
                                @php($status = $this->dripStatus($drip))
                                <div wire:key="shift-drip-{{ $drip->id }}" class="mb-2">
                                    <div class="flex items-start justify-between gap-3">
                                        <p @class(['text-sm font-medium text-zinc-800', 'text-zinc-400 line-through' => $status === 'given'])>
                                            {{ $drip->name }}
                                        </p>
                                        <x-shift-order-status :status="$status" :label="$this->statusLabel($status)" />
                                    </div>
                                    @foreach ($drip->additives as $additive)
                                        <p class="ms-3 text-sm text-zinc-600">+ {{ $additive->name }}</p>
                                    @endforeach
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>

                <x-slot:footer>
                    <flux:button type="button" variant="ghost" wire:click="backToList" class="w-full">
                        {{ __('Back to list') }}
                    </flux:button>
                </x-slot:footer>
            </x-paper-slip>
        @else
            <div class="flex items-center justify-between">
                <flux:heading level="2" size="md">{{ __('Patients with medication or drip orders') }}</flux:heading>
                <flux:badge color="zinc" size="lg">{{ $this->orders->count() }}</flux:badge>
            </div>

            <div class="grid flex-1 grid-cols-1 content-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @if ($this->orders->isEmpty())
                    <div class="col-span-full flex flex-1 flex-col items-center justify-center gap-2 rounded-sm border border-dashed border-zinc-700 px-6 py-16 text-center">
                        <flux:icon name="clipboard-document-check" class="size-10 text-zinc-500" />
                        <p class="text-base font-medium">{{ __('No medication or drip orders') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('Patients with prescriptions in this shift will appear here.') }}</p>
                    </div>
                @else
                    @foreach ($this->orders as $order)
                        @php($pendingMedicines = $order->medicines->whereNull('delivered_at')->count())
                        @php($pendingInjections = $order->injections->whereNull('delivered_at')->count())
                        @php($activeDrips = $order->drips->filter(fn ($drip) => $drip->isActive())->count())
                        <x-paper-slip
                            as="button"
                            type="button"
                            :token="$order->queueToken?->token_number"
                            wire:key="shift-order-{{ $order->id }}"
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
                                @if ($order->medicines->isNotEmpty())
                                    <span>{{ $order->medicines->count() }} {{ __('Medicines') }}</span>
                                @endif
                                @if ($order->injections->isNotEmpty())
                                    <span>{{ $order->injections->count() }} {{ __('Injections') }}</span>
                                @endif
                                @if ($order->drips->isNotEmpty())
                                    <span>{{ $order->drips->count() }} {{ __('Drips') }}</span>
                                @endif
                            </div>
                            @if ($pendingMedicines > 0 || $pendingInjections > 0 || $activeDrips > 0)
                                <p class="mt-auto pt-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-400">
                                    {{ __('Open to view status') }}
                                </p>
                            @else
                                <p class="mt-auto pt-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                    {{ __('All given') }}
                                </p>
                            @endif
                        </x-paper-slip>
                    @endforeach
                @endif
            </div>
        @endif
    </div>

    <a
        href="{{ route('display.er') }}"
        class="fixed bottom-5 end-5 z-40 inline-flex items-center gap-2 rounded-full border border-zinc-700 bg-zinc-100 px-5 py-3 text-sm font-semibold text-zinc-900 shadow-lg shadow-black/40 transition hover:bg-white"
    >
        {{ __('Back to ER') }}
    </a>

    <flux:modal wire:model="showExportModal" class="w-full max-w-md">
        <div class="space-y-4">
            <div>
                <flux:heading level="2">{{ __('Export shift orders') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    {{ __('Choose what to include for the selected shift, then open the printable list.') }}
                </flux:text>
            </div>

            <flux:radio.group wire:model="exportType" class="flex flex-col gap-3">
                <flux:radio value="all" :label="__('All')" />
                <flux:radio value="medicine" :label="__('Medicines')" />
                <flux:radio value="injection" :label="__('Injections')" />
                <flux:radio value="drip" :label="__('Drips')" />
            </flux:radio.group>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeExportModal">
                    {{ __('Cancel') }}
                </flux:button>
                @if ($this->exportUrl())
                    <flux:button
                        type="button"
                        variant="primary"
                        href="{{ $this->exportUrl() }}"
                        target="_blank"
                        wire:click="closeExportModal"
                    >
                        {{ __('Open list') }}
                    </flux:button>
                @else
                    <flux:button type="button" variant="primary" disabled>
                        {{ __('Open list') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
