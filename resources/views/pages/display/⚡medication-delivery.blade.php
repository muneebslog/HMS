<?php

use App\Enums\DripLineStatus;
use App\Enums\MedicationOrderStatus;
use App\Enums\StationType;
use App\Models\MedicationOrder;
use App\Models\QueueToken;
use App\Models\Shift;
use App\Services\HealthAidePinSession;
use App\Services\StationSessionService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.display')] #[Title('ER Station')] class extends Component
{
    public ?int $selectedOrderId = null;

    public ?int $selectedTokenId = null;

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
     * Combined ER queue: pending med/injection deliveries and appear_on_er service visits.
     *
     * @return Collection<int, array{type: string, key: string, sort_at: \Illuminate\Support\Carbon, order: ?MedicationOrder, token: ?QueueToken}>
     */
    #[Computed]
    public function queueItems(): Collection
    {
        $shift = Shift::current();

        if ($shift === null) {
            return collect();
        }

        $orders = MedicationOrder::query()
            ->with([
                'patient',
                'doctor',
                'queueToken.serviceQueue.service',
                'medicines',
                'injections',
                'drips.dripBase',
                'drips.additives',
            ])
            ->where('status', MedicationOrderStatus::Pending)
            ->where(function ($query): void {
                $query->whereHas('medicines', fn ($q) => $q->whereNull('delivered_at'))
                    ->orWhereHas('injections', fn ($q) => $q->whereNull('delivered_at'));
            })
            ->whereDoesntHave('drips', fn ($q) => $q->whereIn('status', DripLineStatus::activeCases()))
            ->whereHas('queueToken.serviceQueue', function ($query) use ($shift): void {
                $query->forShift($shift);
            })
            ->orderBy('created_at')
            ->get();

        $orderTokenIds = $orders->pluck('queue_token_id')->filter()->all();

        $serviceTokens = QueueToken::query()
            ->with(['patient', 'serviceQueue.service', 'medicationOrder.medicines', 'medicationOrder.injections', 'medicationOrder.drips'])
            ->whereIn('status', ['waiting', 'serving'])
            ->whereHas('serviceQueue', function ($query) use ($shift): void {
                $query->forShift($shift)
                    ->where('status', 'open')
                    ->whereHas('service', fn ($serviceQuery) => $serviceQuery->where('appear_on_er', true));
            })
            ->when($orderTokenIds !== [], fn ($query) => $query->whereNotIn('id', $orderTokenIds))
            ->where(function ($query): void {
                $query->whereDoesntHave('medicationOrder')
                    ->orWhereHas('medicationOrder', function ($orderQuery): void {
                        $orderQuery->where(function ($statusQuery): void {
                            $statusQuery->where('status', MedicationOrderStatus::Administered)
                                ->orWhere(function ($pendingQuery): void {
                                    $pendingQuery->where('status', MedicationOrderStatus::Pending)
                                        ->whereDoesntHave('medicines', fn ($q) => $q->whereNull('delivered_at'))
                                        ->whereDoesntHave('injections', fn ($q) => $q->whereNull('delivered_at'));
                                });
                        });
                    });
            })
            ->whereDoesntHave(
                'medicationOrder.drips',
                fn ($q) => $q->whereIn('status', DripLineStatus::activeCases())
            )
            ->orderBy('token_number')
            ->get();

        $items = collect();

        foreach ($orders as $order) {
            $items->push([
                'type' => 'medication',
                'key' => 'order-'.$order->id,
                'sort_at' => $order->created_at,
                'order' => $order,
                'token' => $order->queueToken,
            ]);
        }

        foreach ($serviceTokens as $token) {
            $items->push([
                'type' => 'service',
                'key' => 'token-'.$token->id,
                'sort_at' => $token->arrived_at ?? $token->created_at,
                'order' => null,
                'token' => $token,
            ]);
        }

        return $items->sortBy('sort_at')->values();
    }

    /**
     * @deprecated Use queueItems; kept for existing delivery flow selection.
     *
     * @return Collection<int, MedicationOrder>
     */
    #[Computed]
    public function orders(): Collection
    {
        return $this->queueItems
            ->where('type', 'medication')
            ->pluck('order')
            ->filter()
            ->values();
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
                'drips.dripBase',
                'drips.additives',
            ])->find($this->selectedOrderId);
    }

    #[Computed]
    public function selectedToken(): ?QueueToken
    {
        if ($this->selectedTokenId === null) {
            return null;
        }

        $fromQueue = $this->queueItems
            ->where('type', 'service')
            ->firstWhere(fn (array $item): bool => $item['token']?->id === $this->selectedTokenId);

        return $fromQueue['token']
            ?? QueueToken::with(['patient', 'serviceQueue.service', 'medicationOrder.drips'])->find($this->selectedTokenId);
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
        $this->selectedTokenId = null;
        $this->selectedMedicineIds = [];
        $this->selectedInjectionIds = [];
    }

    public function selectServiceToken(int $tokenId): void
    {
        $exists = $this->queueItems
            ->where('type', 'service')
            ->contains(fn (array $item): bool => $item['token']?->id === $tokenId);

        if (! $exists) {
            Flux::toast(variant: 'danger', text: __('Visit is no longer pending.'));

            return;
        }

        $this->selectedTokenId = $tokenId;
        $this->selectedOrderId = null;
        $this->selectedMedicineIds = [];
        $this->selectedInjectionIds = [];
    }

    public function backToList(): void
    {
        $this->selectedOrderId = null;
        $this->selectedTokenId = null;
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

    public function requestCompleteService(): void
    {
        if ($this->selectedToken === null) {
            return;
        }

        $this->requirePinThen('completeService');
    }

    public function verifyPin(HealthAidePinSession $pinSession, StationSessionService $stationSessions): void
    {
        $this->validate([
            'pin' => ['required', 'digits_between:4,6'],
        ]);

        $aide = $pinSession->attempt($this->pin);

        if ($aide === null) {
            $this->addError('pin', __('Invalid PIN.'));

            return;
        }

        $stationSessions->touch(StationType::Er, $aide);

        $this->pin = '';
        $this->showPinModal = false;
        $this->resetValidation();

        $action = $this->pendingAction;
        $this->pendingAction = null;

        if ($action === 'deliverNext') {
            $this->deliverNext();
        } elseif ($action === 'completeService') {
            $this->completeService();
        }
    }

    public function lock(HealthAidePinSession $pinSession, StationSessionService $stationSessions): void
    {
        $pinSession->forget();
        $stationSessions->clear(StationType::Er);
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
            unset($this->queueItems, $this->orders, $this->selectedOrder);

            return;
        }

        if ($order->hasActiveDrips()) {
            Flux::toast(variant: 'danger', text: __('Finish the drip at the Drip Station before delivering medication.'));
            $this->backToList();
            unset($this->queueItems, $this->orders, $this->selectedOrder);

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

            $token = $order->queueToken;
            $token?->loadMissing('serviceQueue.service');

            if ($token !== null
                && $token->serviceQueue?->service?->appear_on_er
                && $order->fresh()->status === MedicationOrderStatus::Administered) {
                $token->update(['status' => 'served']);
            }
        });

        app(StationSessionService::class)->bump(StationType::Er, $aide);

        unset($this->queueItems, $this->orders, $this->selectedOrder);

        Flux::toast(variant: 'success', text: __('Delivery saved.'));

        $next = $this->queueItems->first();

        if ($next === null) {
            $this->backToList();
            Flux::toast(text: __('No more pending ER work.'));

            return;
        }

        if ($next['type'] === 'medication' && $next['order'] !== null) {
            $this->selectOrder($next['order']->id);
        } elseif ($next['type'] === 'service' && $next['token'] !== null) {
            $this->selectServiceToken($next['token']->id);
        } else {
            $this->backToList();
        }
    }

    public function completeService(): void
    {
        $aide = app(HealthAidePinSession::class)->current();

        if ($aide === null) {
            $this->requirePinThen('completeService');

            return;
        }

        $token = $this->selectedToken;

        if ($token === null || ! in_array($token->status, ['waiting', 'serving'], true)) {
            Flux::toast(variant: 'danger', text: __('Visit is no longer pending.'));
            $this->backToList();
            unset($this->queueItems, $this->selectedToken);

            return;
        }

        if (! $token->serviceQueue?->service?->appear_on_er) {
            Flux::toast(variant: 'danger', text: __('Service is not an ER visit.'));

            return;
        }

        if ($token->medicationOrder?->hasActiveDrips()) {
            Flux::toast(variant: 'danger', text: __('Finish the drip at the Drip Station before completing this visit.'));
            $this->backToList();
            unset($this->queueItems, $this->selectedToken);

            return;
        }

        $token->update(['status' => 'served']);

        app(StationSessionService::class)->bump(StationType::Er, $aide);

        unset($this->queueItems, $this->selectedToken);

        Flux::toast(variant: 'success', text: __('ER visit completed.'));

        $next = $this->queueItems->first();

        if ($next === null) {
            $this->backToList();

            return;
        }

        if ($next['type'] === 'medication' && $next['order'] !== null) {
            $this->selectOrder($next['order']->id);
        } elseif ($next['type'] === 'service' && $next['token'] !== null) {
            $this->selectServiceToken($next['token']->id);
        } else {
            $this->backToList();
        }
    }

    protected function requirePinThen(string $action): void
    {
        if (app(HealthAidePinSession::class)->check()) {
            if ($action === 'deliverNext') {
                $this->deliverNext();
            } elseif ($action === 'completeService') {
                $this->completeService();
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
            <flux:heading level="1" size="lg">{{ __('ER Station') }}</flux:heading>
            @if ($this->currentAideName)
                <flux:text class="text-zinc-400">{{ __('Signed in as') }} {{ $this->currentAideName }}</flux:text>
            @endif
        </div>
        <flux:button type="button" variant="ghost" icon="lock-closed" wire:click="lock">
            {{ __('Lock') }}
        </flux:button>
    </div>

    <div class="flex flex-1 flex-col gap-4 p-4">
        @if ($selectedOrderId === null && $selectedTokenId === null)
            <div class="flex items-center justify-between">
                <flux:heading level="2" size="md">{{ __('Pending ER work') }}</flux:heading>
                <flux:badge color="zinc" size="lg">{{ $this->queueItems->count() }}</flux:badge>
            </div>

            <div class="grid flex-1 grid-cols-1 content-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @forelse ($this->queueItems as $item)
                    @if ($item['type'] === 'medication')
                        @php
                            $order = $item['order'];
                            $pendingMedicines = $order->medicines->whereNull('delivered_at')->count();
                            $pendingInjections = $order->injections->whereNull('delivered_at')->count();
                            $erDrips = $order->drips->filter(fn ($drip) => $drip->dripBase?->show_on_er);
                        @endphp
                        <x-paper-slip
                            as="button"
                            type="button"
                            :token="$order->queueToken?->token_number"
                            wire:key="er-order-{{ $order->id }}"
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
                                @if ($erDrips->isNotEmpty())
                                    <span>{{ $erDrips->count() }} {{ __('Drips') }}</span>
                                @endif
                            </div>
                            @if (filled($order->notes))
                                <div class="border-t border-dashed border-zinc-400/70 pt-2 text-xs text-zinc-700">
                                    <span class="font-semibold">{{ __('Notes:') }}</span>
                                    <span class="whitespace-pre-line">{{ $order->notes }}</span>
                                </div>
                            @endif
                            <p class="mt-auto pt-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-400">
                                {{ __('Tap to deliver') }}
                            </p>
                        </x-paper-slip>
                    @else
                        @php($token = $item['token'])
                        <x-paper-slip
                            as="button"
                            type="button"
                            :token="$token->token_number"
                            wire:key="er-token-{{ $token->id }}"
                            wire:click="selectServiceToken({{ $token->id }})"
                            class="active:scale-[0.99] hover:-translate-y-0.5 hover:shadow-[0_1px_0_rgba(255,255,255,0.85)_inset,0_4px_8px_rgba(0,0,0,0.08),0_16px_28px_rgba(0,0,0,0.14)]"
                        >
                            <p class="truncate text-base font-semibold text-zinc-900">
                                {{ $token->patient?->name ?? __('Unknown') }}
                            </p>
                            <p class="truncate text-xs uppercase tracking-wide text-zinc-500">
                                {{ $token->patient?->mrn ?? __('No MRN') }}
                                · {{ $token->serviceQueue?->service?->name }}
                            </p>
                            <div class="mt-1 border-t border-dashed border-zinc-400/70 pt-2 text-xs text-zinc-600">
                                {{ __('ER service') }}
                            </div>
                            <p class="mt-auto pt-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-400">
                                {{ __('Tap to complete') }}
                            </p>
                        </x-paper-slip>
                    @endif
                @empty
                    <div class="col-span-full flex flex-1 flex-col items-center justify-center gap-2 rounded-sm border border-dashed border-zinc-700 px-6 py-16 text-center">
                        <flux:icon name="clipboard-document-check" class="size-10 text-zinc-500" />
                        <p class="text-base font-medium">{{ __('No pending ER work') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('Prescriptions and ER services will appear here.') }}</p>
                    </div>
                @endforelse
            </div>
        @elseif ($selectedOrderId !== null)
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
                                    {{ $medicine->name }} — {{ $medicine->dose->label() }}
                                    @if (filled($medicine->comment))
                                        · {{ $medicine->comment }}
                                    @endif
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
                                        <span class="text-zinc-500">
                                            — {{ $medicine->dose->label() }}
                                            @if (filled($medicine->comment))
                                                · {{ $medicine->comment }}
                                            @endif
                                        </span>
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
                                            @if (filled($injection->comment))
                                                · {{ $injection->comment }}
                                            @endif
                                        </span>
                                    </span>
                                </label>
                            @endif
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @endforelse
                    </div>

                    @php($erDrips = ($order?->drips ?? collect())->filter(fn ($drip) => $drip->dripBase?->show_on_er))
                    @if ($erDrips->isNotEmpty())
                        <div class="border-t border-dashed border-zinc-400/70 pt-3">
                            <p class="mb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Drips') }}</p>
                            @foreach ($erDrips as $drip)
                                <div wire:key="er-drip-{{ $drip->id }}" class="mb-2">
                                    <p class="text-sm font-medium text-zinc-800">
                                        {{ $drip->name }}
                                    </p>
                                    @foreach ($drip->additives as $additive)
                                        <p class="ms-3 text-sm text-zinc-600">
                                            + {{ $additive->name }}
                                        </p>
                                    @endforeach
                                </div>
                            @endforeach
                            <p class="text-xs text-zinc-500">{{ __('Start and complete drips at the Drip Station.') }}</p>
                        </div>
                    @endif

                    @if (filled($order?->complaint_or_diagnosis))
                        <div class="border-t border-dashed border-zinc-400/70 pt-3">
                            <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Complaint / diagnosis') }}</p>
                            <p class="whitespace-pre-line text-sm text-zinc-800">{{ $order->complaint_or_diagnosis }}</p>
                        </div>
                    @endif

                    @if (filled($order?->notes))
                        <div class="border-t border-dashed border-zinc-400/70 pt-3">
                            <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Notes') }}</p>
                            <p class="whitespace-pre-line text-sm text-zinc-800">{{ $order->notes }}</p>
                        </div>
                    @endif
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
        @else
            @php($token = $this->selectedToken)
            <x-paper-slip
                :token="$token?->token_number"
                class="mx-auto w-full max-w-lg"
            >
                <div class="space-y-1">
                    <p class="truncate text-lg font-semibold text-zinc-900">{{ $token?->patient?->name ?? __('Unknown') }}</p>
                    <p class="truncate text-xs uppercase tracking-wide text-zinc-500">
                        {{ $token?->patient?->mrn ?? __('No MRN') }}
                        · {{ $token?->serviceQueue?->service?->name }}
                    </p>
                </div>

                <div class="border-t border-dashed border-zinc-400/70 pt-3 text-sm text-zinc-700">
                    {{ __('Mark this ER service as completed when finished.') }}
                </div>

                <x-slot:footer>
                    <div class="flex flex-col gap-3">
                        <flux:button
                            type="button"
                            variant="primary"
                            class="h-12 w-full text-base font-semibold"
                            wire:click="requestCompleteService"
                        >
                            {{ __('Mark completed') }}
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
