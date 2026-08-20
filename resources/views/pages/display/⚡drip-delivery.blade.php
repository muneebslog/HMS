<?php

use App\Enums\DripLineStatus;
use App\Enums\StationType;
use App\Models\MedicationOrderDrip;
use App\Services\HealthAidePinSession;
use App\Services\StationSessionService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.display')] #[Title('Drip Delivery')] class extends Component
{
    public string $pin = '';

    public bool $showPinModal = false;

    public ?string $pendingAction = null;

    public ?int $pendingDripId = null;

    public function mount(HealthAidePinSession $pinSession): void
    {
        if (! $pinSession->check()) {
            $this->showPinModal = true;
        }
    }

    /**
     * Drip lines that are not done, regardless of whether their originating shift is open.
     *
     * @return Collection<int, MedicationOrderDrip>
     */
    #[Computed]
    public function drips(): Collection
    {
        return MedicationOrderDrip::query()
            ->with([
                'additives',
                'startedByHealthAide',
                'medicationOrder.patient',
                'medicationOrder.doctor',
                'medicationOrder.queueToken.serviceQueue.service',
                'medicationOrder.dripCharges',
                'medicationOrder.medicines',
                'medicationOrder.injections',
            ])
            ->whereIn('status', DripLineStatus::activeCases())
            ->orderByRaw("CASE WHEN status = 'started' AND check_due_at IS NOT NULL AND check_due_at <= ? THEN 0 WHEN status = 'pending' THEN 1 ELSE 2 END", [now()])
            ->orderBy('check_due_at')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function currentAideName(): ?string
    {
        return app(HealthAidePinSession::class)->current()?->name;
    }

    public function requestStart(int $dripId): void
    {
        $this->pendingDripId = $dripId;
        $this->requirePinThen('start');
    }

    public function requestMarkDone(int $dripId): void
    {
        $this->pendingDripId = $dripId;
        $this->requirePinThen('done');
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

        $stationSessions->touch(StationType::Drip, $aide);

        $this->pin = '';
        $this->showPinModal = false;
        $this->resetValidation();

        $action = $this->pendingAction;
        $this->pendingAction = null;

        if ($action === 'start') {
            $this->startDrip();
        } elseif ($action === 'done') {
            $this->markDone();
        }
    }

    public function lock(HealthAidePinSession $pinSession, StationSessionService $stationSessions): void
    {
        $pinSession->forget();
        $stationSessions->clear(StationType::Drip);
        $this->showPinModal = true;
        $this->pendingAction = null;
        $this->pendingDripId = null;
        Flux::toast(text: __('Session locked.'));
    }

    public function notifyDueChecks(): void
    {
        $due = MedicationOrderDrip::query()
            ->with(['medicationOrder.patient'])
            ->where('status', DripLineStatus::Started)
            ->whereNotNull('check_due_at')
            ->where('check_due_at', '<=', now())
            ->whereNull('check_notified_at')
            ->get();

        foreach ($due as $drip) {
            $drip->update(['check_notified_at' => now()]);

            $patient = $drip->medicationOrder?->patient?->name ?? __('Unknown');

            Flux::toast(
                variant: 'warning',
                text: __('Check drip for :patient — :drip', [
                    'patient' => $patient,
                    'drip' => $drip->name,
                ]),
            );
        }

        if ($due->isNotEmpty()) {
            unset($this->drips);
        }
    }

    public function startDrip(): void
    {
        $aide = app(HealthAidePinSession::class)->current();

        if ($aide === null) {
            $this->requirePinThen('start');

            return;
        }

        $drip = MedicationOrderDrip::query()
            ->with('medicationOrder.dripCharges')
            ->find($this->pendingDripId);

        if ($drip === null || $drip->status !== DripLineStatus::Pending) {
            Flux::toast(variant: 'danger', text: __('Drip is no longer pending.'));
            $this->pendingDripId = null;
            unset($this->drips);

            return;
        }

        if ($drip->medicationOrder?->hasUnpaidDripCharge()) {
            Flux::toast(variant: 'danger', text: __('Not yet paid.'));
            $this->pendingDripId = null;

            return;
        }

        $drip->update([
            'status' => DripLineStatus::Started,
            'started_at' => now(),
            'started_by_health_aide_id' => $aide->id,
            'check_due_at' => now()->addMinutes(30),
            'check_notified_at' => null,
        ]);

        app(StationSessionService::class)->bump(StationType::Drip, $aide);

        $this->pendingDripId = null;
        unset($this->drips);

        Flux::toast(variant: 'success', text: __('Drip started. Check again in 30 minutes.'));
    }

    public function markDone(): void
    {
        $aide = app(HealthAidePinSession::class)->current();

        if ($aide === null) {
            $this->requirePinThen('done');

            return;
        }

        $drip = MedicationOrderDrip::query()->find($this->pendingDripId);

        if ($drip === null || $drip->status !== DripLineStatus::Started) {
            Flux::toast(variant: 'danger', text: __('Drip cannot be marked done.'));
            $this->pendingDripId = null;
            unset($this->drips);

            return;
        }

        $drip->update([
            'status' => DripLineStatus::Done,
            'done_at' => now(),
            'done_by_health_aide_id' => $aide->id,
        ]);

        app(StationSessionService::class)->bump(StationType::Drip, $aide);

        $this->pendingDripId = null;
        unset($this->drips);

        Flux::toast(variant: 'success', text: __('Drip marked done.'));
    }

    protected function requirePinThen(string $action): void
    {
        if (app(HealthAidePinSession::class)->check()) {
            if ($action === 'start') {
                $this->startDrip();
            } elseif ($action === 'done') {
                $this->markDone();
            }

            return;
        }

        $this->pendingAction = $action;
        $this->pin = '';
        $this->showPinModal = true;
        $this->resetValidation();
    }
}; ?>

<div class="paper-slip-board flex min-h-screen flex-col bg-zinc-950 text-white" wire:poll.10s="notifyDueChecks">
    <div class="flex items-center justify-between gap-3 border-b border-zinc-800 px-4 py-3">
        <div>
            <flux:heading level="1" size="lg">{{ __('Drip Delivery') }}</flux:heading>
            @if ($this->currentAideName)
                <flux:text class="text-zinc-400">{{ __('Signed in as') }} {{ $this->currentAideName }}</flux:text>
            @endif
        </div>
        <flux:button type="button" variant="ghost" icon="lock-closed" wire:click="lock">
            {{ __('Lock') }}
        </flux:button>
    </div>

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <flux:heading level="2" size="md">{{ __('Active drips') }}</flux:heading>
            <flux:badge color="zinc" size="lg">{{ $this->drips->count() }}</flux:badge>
        </div>

        <div class="grid flex-1 grid-cols-1 content-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->drips->groupBy('medication_order_id') as $orderId => $orderDrips)
                @php
                    $orderDrips = $orderDrips->sortBy('id')->values();
                    $order = $orderDrips->first()?->medicationOrder;
                    $overdue = $orderDrips->contains(fn (MedicationOrderDrip $drip): bool => $drip->isCheckDue());
                    $unpaid = $order?->hasUnpaidDripCharge() ?? false;
                    $orderMedicines = $order?->medicines ?? collect();
                    $orderInjections = $order?->injections ?? collect();
                    $hasContext = filled($order?->notes)
                        || filled($order?->complaint_or_diagnosis)
                        || $orderMedicines->isNotEmpty()
                        || $orderInjections->isNotEmpty();
                @endphp
                <x-paper-slip
                    wire:key="drip-delivery-{{ $orderId }}"
                    :token="$order?->queueToken?->token_number"
                    :tone="$unpaid ? 'locked' : ($overdue ? 'accent' : 'default')"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-base font-semibold text-zinc-900">{{ $order?->patient?->name ?? __('Unknown') }}</p>
                            @if ($unpaid)
                                <flux:badge size="sm" color="red">{{ __('Unpaid') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge>
                            @endif
                        </div>
                        <p class="truncate text-xs uppercase tracking-wide text-zinc-500">
                            {{ $order?->patient?->mrn ?? __('No MRN') }}
                            · {{ $order?->queueToken?->serviceQueue?->service?->name }}
                        </p>
                    </div>

                    @foreach ($orderDrips as $drip)
                        @php($dripOverdue = $drip->isCheckDue())
                        <div wire:key="drip-delivery-line-{{ $drip->id }}" class="space-y-2 border-t border-dashed border-zinc-400/70 pt-2">
                            <div>
                                <p class="font-medium text-zinc-900">
                                    {{ $drip->name }}
                                </p>
                                @foreach ($drip->additives as $additive)
                                    <p class="ms-1 text-sm text-zinc-600">
                                        + {{ $additive->name }}
                                    </p>
                                @endforeach
                            </div>

                            <div class="flex flex-wrap items-center gap-2 text-black">
                                <flux:badge size="sm" :color="$drip->status === \App\Enums\DripLineStatus::Pending ? 'zinc' : ($dripOverdue ? 'amber' : 'sky')">
                                    {{ $drip->status->label() }}
                                    @if ($dripOverdue)
                                        · {{ __('Check due') }}
                                    @elseif ($drip->status === \App\Enums\DripLineStatus::Started && $drip->check_due_at)
                                        · {{ __('Check at') }} {{ $drip->check_due_at->timezone(config('app.timezone'))->format('h:i A') }}
                                    @endif
                                </flux:badge>
                                @if ($drip->startedByHealthAide)
                                    <span class="text-xs text-zinc-500">
                                        {{ __('Started by') }} {{ $drip->startedByHealthAide->name }}
                                    </span>
                                @endif
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <flux:button
                                    type="button"
                                    variant="primary"
                                    class="w-full"
                                    :disabled="$unpaid || $drip->status !== \App\Enums\DripLineStatus::Pending"
                                    wire:click="requestStart({{ $drip->id }})"
                                >
                                    {{ __('Start') }}
                                </flux:button>
                                <flux:button
                                    type="button"
                                    variant="primary"
                                    class="w-full"
                                    :disabled="$drip->status !== \App\Enums\DripLineStatus::Started"
                                    wire:click="requestMarkDone({{ $drip->id }})"
                                >
                                    {{ __('End') }}
                                </flux:button>
                            </div>
                        </div>
                    @endforeach

                    @if ($hasContext)
                        <div class="space-y-2 border-t border-dashed border-zinc-300/80 pt-2 opacity-55">
                            @if ($orderMedicines->isNotEmpty())
                                <div>
                                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Medicines') }}</p>
                                    @foreach ($orderMedicines as $medicine)
                                        <p wire:key="drip-slip-med-{{ $medicine->id }}" @class([
                                            'text-xs text-zinc-600',
                                            'line-through' => $medicine->delivered_at !== null,
                                        ])>
                                            {{ $medicine->name }}
                                            <span class="text-zinc-500">
                                                — {{ $medicine->dose->label() }}
                                                @if (filled($medicine->comment))
                                                    · {{ $medicine->comment }}
                                                @endif
                                            </span>
                                        </p>
                                    @endforeach
                                </div>
                            @endif

                            @if ($orderInjections->isNotEmpty())
                                <div>
                                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Injections') }}</p>
                                    @foreach ($orderInjections as $injection)
                                        <p wire:key="drip-slip-inj-{{ $injection->id }}" @class([
                                            'text-xs text-zinc-600',
                                            'line-through' => $injection->delivered_at !== null,
                                        ])>
                                            {{ $injection->name }}
                                            <span class="text-zinc-500">
                                                — {{ $injection->administration_type->label() }}
                                                @if (filled($injection->comment))
                                                    · {{ $injection->comment }}
                                                @endif
                                            </span>
                                        </p>
                                    @endforeach
                                </div>
                            @endif

                            @if (filled($order?->complaint_or_diagnosis))
                                <div>
                                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Complaint / diagnosis') }}</p>
                                    <p class="whitespace-pre-line text-xs text-zinc-600">{{ $order->complaint_or_diagnosis }}</p>
                                </div>
                            @endif

                            @if (filled($order?->notes))
                                <div>
                                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ __('Notes') }}</p>
                                    <p class="whitespace-pre-line text-xs text-zinc-600">{{ $order->notes }}</p>
                                </div>
                            @endif
                        </div>
                    @endif
                </x-paper-slip>
            @empty
                <div class="col-span-full flex flex-1 flex-col items-center justify-center gap-2 rounded-sm border border-dashed border-zinc-700 px-6 py-16 text-center">
                    <flux:icon name="beaker" class="size-10 text-zinc-500" />
                    <p class="text-base font-medium">{{ __('No active drips') }}</p>
                    <p class="text-sm text-zinc-500">{{ __('Pending drip orders will appear here to start and check.') }}</p>
                </div>
            @endforelse
        </div>
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
