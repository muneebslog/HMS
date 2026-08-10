<?php

use App\Models\DoctorRecheck;
use App\Models\QueueToken;
use App\Models\Shift;
use App\Models\Vital;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Vitals')] class extends Component
{
    public ?int $selectedTokenId = null;

    #[Validate]
    public string $temperatureFahrenheit = '';

    #[Validate]
    public string $bpSystolic = '';

    #[Validate]
    public string $bpDiastolic = '';

    #[Validate]
    public string $bsr = '';

    /**
     * Get the validation rules for vitals capture.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'temperatureFahrenheit' => ['required', 'numeric', 'min:86', 'max:113'],
            'bpSystolic' => ['required', 'integer', 'min:50', 'max:300'],
            'bpDiastolic' => ['required', 'integer', 'min:30', 'max:200'],
            'bsr' => ['nullable', 'integer', 'min:20', 'max:600'],
        ];
    }

    /**
     * Waiting tokens that still need vitals, plus due recheck patients awaiting redo.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function queue(): Collection
    {
        $shift = Shift::current();

        if ($shift === null) {
            return new Collection;
        }

        return QueueToken::query()
            ->with(['patient', 'serviceQueue.service', 'serviceQueue.doctor', 'vital', 'activeRecheck'])
            ->where(function ($query) use ($shift): void {
                $query->where(function ($initial) use ($shift): void {
                    $initial->where('status', 'waiting')
                        ->whereDoesntHave('vital')
                        ->whereHas('serviceQueue', function ($serviceQueue) use ($shift): void {
                            $serviceQueue->where('status', 'open')
                                ->where('shift_id', $shift->id)
                                ->whereHas('service', fn ($serviceQuery) => $serviceQuery->where('needs_vitals', true));
                        });
                })->orWhere(function ($recheck) use ($shift): void {
                    $recheck->whereIn('status', ['waiting', 'serving'])
                        ->whereHas('activeRecheck', fn ($activeRecheck) => $activeRecheck
                            ->where('due_at', '<=', now())
                            ->whereNull('vitals_redone_at'))
                        ->whereHas('serviceQueue', function ($serviceQueue) use ($shift): void {
                            $serviceQueue->where('status', 'open')
                                ->where('shift_id', $shift->id);
                        });
                });
            })
            ->orderByRaw('arrived_at is null')
            ->orderBy('arrived_at')
            ->orderBy('token_number')
            ->get()
            ->sortByDesc(fn (QueueToken $token): int => $this->isRecheckCapture($token) ? 1 : 0)
            ->values();
    }

    /**
     * The token currently being captured.
     */
    #[Computed]
    public function selectedToken(): ?QueueToken
    {
        if ($this->selectedTokenId === null) {
            return null;
        }

        return $this->queue->firstWhere('id', $this->selectedTokenId)
            ?? QueueToken::with(['patient', 'serviceQueue.service', 'serviceQueue.doctor', 'vital', 'activeRecheck'])
                ->find($this->selectedTokenId);
    }

    /**
     * Whether this token is in the queue for a due recheck vitals redo.
     */
    private function isRecheckCapture(?QueueToken $token): bool
    {
        $recheck = $token?->activeRecheck;

        return $recheck !== null
            && $recheck->isDue()
            && ! $recheck->hasVitalsRedone();
    }

    /**
     * Select a patient from the queue for vitals capture.
     */
    public function selectToken(int $tokenId): void
    {
        $token = $this->queue->firstWhere('id', $tokenId);

        if ($token === null) {
            Flux::toast(variant: 'danger', text: __('Patient is no longer in the vitals queue.'));

            return;
        }

        $this->selectedTokenId = $tokenId;
        $this->resetCaptureFields();

        if ($token->vital !== null && $this->isRecheckCapture($token)) {
            $this->temperatureFahrenheit = (string) $token->vital->temperature;
            $this->bpSystolic = (string) $token->vital->bp_systolic;
            $this->bpDiastolic = (string) $token->vital->bp_diastolic;
            $this->bsr = $token->vital->bsr !== null ? (string) $token->vital->bsr : '';
        }

        $this->resetValidation();
    }

    /**
     * Return to the queue list without saving.
     */
    public function backToList(): void
    {
        $this->selectedTokenId = null;
        $this->resetCaptureFields();
        $this->resetValidation();
    }

    /**
     * Save vitals and advance to the next patient in the queue.
     */
    public function saveAndNext(): void
    {
        $validated = $this->validate();

        $shift = Shift::current();

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('Please open a shift first.'));

            return;
        }

        $token = $this->selectedToken;

        if ($token === null || $token->patient_id === null) {
            Flux::toast(variant: 'danger', text: __('Patient not found.'));
            $this->backToList();

            return;
        }

        $isRecheck = $this->isRecheckCapture($token);

        if ($isRecheck) {
            if (! in_array($token->status, ['waiting', 'serving'], true)) {
                Flux::toast(variant: 'danger', text: __('Patient is no longer in the vitals queue.'));
                $this->backToList();

                return;
            }
        } elseif ($token->status !== 'waiting' || $token->vital()->exists()) {
            Flux::toast(variant: 'danger', text: __('Patient is no longer in the vitals queue.'));
            $this->backToList();

            return;
        } elseif (! $token->serviceQueue?->service?->needs_vitals) {
            Flux::toast(variant: 'danger', text: __('This service does not require vitals.'));
            $this->backToList();

            return;
        }

        $vitalAttributes = [
            'patient_id' => $token->patient_id,
            'recorded_by' => auth()->id(),
            'temperature' => $validated['temperatureFahrenheit'],
            'bp_systolic' => $validated['bpSystolic'],
            'bp_diastolic' => $validated['bpDiastolic'],
            'bsr' => filled($validated['bsr'] ?? null) ? $validated['bsr'] : null,
        ];

        Vital::create([
            'queue_token_id' => $token->id,
            ...$vitalAttributes,
        ]);

        if ($isRecheck) {
            DoctorRecheck::query()
                ->where('queue_token_id', $token->id)
                ->whereNull('acknowledged_at')
                ->whereNull('vitals_redone_at')
                ->where('due_at', '<=', now())
                ->update(['vitals_redone_at' => now()]);
        }

        unset($this->queue);

        $nextToken = $this->queue->first();

        Flux::toast(variant: 'success', text: $isRecheck ? __('Vitals recorded (Again).') : __('Vitals saved.'));

        if ($nextToken === null) {
            $this->backToList();

            return;
        }

        $this->selectedTokenId = $nextToken->id;
        $this->resetCaptureFields();
        $this->resetValidation();
        unset($this->selectedToken);

        if ($nextToken->vital !== null && $this->isRecheckCapture($nextToken)) {
            $this->temperatureFahrenheit = (string) $nextToken->vital->temperature;
            $this->bpSystolic = (string) $nextToken->vital->bp_systolic;
            $this->bpDiastolic = (string) $nextToken->vital->bp_diastolic;
            $this->bsr = $nextToken->vital->bsr !== null ? (string) $nextToken->vital->bsr : '';
        }
    }

    /**
     * Clear temperature and BP fields.
     */
    private function resetCaptureFields(): void
    {
        $this->temperatureFahrenheit = '';
        $this->bpSystolic = '';
        $this->bpDiastolic = '';
        $this->bsr = '';
    }
}; ?>

<div class="paper-slip-board-light flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between gap-3">
        <flux:heading level="1">{{ __('Vitals') }}</flux:heading>
        @if ($selectedTokenId === null)
            <flux:badge color="zinc" size="lg">{{ $this->queue->count() }}</flux:badge>
        @endif
    </div>

    @if ($selectedTokenId === null)
        <div class="grid flex-1 grid-cols-1 content-start gap-4 sm:grid-cols-2 xl:grid-cols-3" wire:poll.10s>
            @forelse ($this->queue as $token)
                @php($isAgain = $token->activeRecheck?->isDue() && ! $token->activeRecheck->hasVitalsRedone())
                <x-paper-slip
                    as="button"
                    type="button"
                    :token="$token->token_number"
                    :tone="$isAgain ? 'accent' : 'default'"
                    wire:key="vitals-token-{{ $token->id }}"
                    wire:click="selectToken({{ $token->id }})"
                    class="active:scale-[0.99] hover:-translate-y-0.5 hover:shadow-[0_1px_0_rgba(255,255,255,0.85)_inset,0_4px_8px_rgba(0,0,0,0.08),0_16px_28px_rgba(0,0,0,0.14)]"
                >
                    <div class="flex items-start justify-between gap-2">
                        <p class="truncate text-base font-semibold text-zinc-900">
                            {{ $token->patient?->name ?? __('Unknown') }}
                        </p>
                        @if ($isAgain)
                            <flux:badge size="sm" color="amber">{{ __('Again') }}</flux:badge>
                        @endif
                    </div>
                    <p class="text-sm text-zinc-600">
                        {{ $token->serviceQueue?->service?->name }}
                    </p>
                    @if ($token->serviceQueue?->doctor)
                        <p class="text-xs uppercase tracking-wide text-zinc-500">
                            {{ $token->serviceQueue->doctor->name }}
                        </p>
                    @endif
                    @if ($isAgain && filled($token->activeRecheck?->note))
                        <p class="border-t border-dashed border-zinc-400/70 pt-2 text-xs text-zinc-600">
                            {{ $token->activeRecheck->note }}
                        </p>
                    @endif
                    <p class="mt-auto pt-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-400">
                        {{ __('Tap to record') }}
                    </p>
                </x-paper-slip>
            @empty
                <div class="col-span-full flex flex-1 flex-col items-center justify-center gap-2 rounded-sm border border-dashed border-zinc-300 bg-[#f7f4ec]/60 px-6 py-16 text-center dark:border-zinc-600">
                    <flux:icon name="heart" class="size-10 text-zinc-400" />
                    <p class="text-base font-medium text-zinc-700 dark:text-zinc-200">{{ __('No patients need vitals') }}</p>
                    <p class="text-sm text-zinc-500">{{ __('Waiting patients for services that need vitals will appear here.') }}</p>
                </div>
            @endforelse
        </div>
    @else
        @php($token = $this->selectedToken)
        @php($isAgain = $token?->activeRecheck?->isDue() && ! $token->activeRecheck->hasVitalsRedone())
        <x-paper-slip
            :token="$token?->token_number"
            :tone="$isAgain ? 'accent' : 'default'"
            class="mx-auto w-full max-w-lg"
        >
            <div class="space-y-1">
                <p class="text-lg font-semibold text-zinc-900">
                    {{ $token?->patient?->name ?? __('Unknown') }}
                    @if ($isAgain)
                        <flux:badge size="sm" color="amber" class="ms-1 align-middle">{{ __('Again') }}</flux:badge>
                    @endif
                </p>
                <p class="text-sm text-zinc-600">
                    {{ $token?->serviceQueue?->service?->name }}
                    @if ($token?->serviceQueue?->doctor)
                        · {{ $token->serviceQueue->doctor->name }}
                    @endif
                </p>
            </div>

            <form wire:submit="saveAndNext" class="mt-2 flex flex-col gap-4 border-t border-dashed border-zinc-400/70 pt-4">
                <flux:field>
                    <flux:label class="text-base">{{ __('Temperature (°F)') }}</flux:label>
                    <flux:input
                        wire:model="temperatureFahrenheit"
                        type="number"
                        inputmode="decimal"
                        step="0.1"
                        min="86"
                        max="113"
                        class="!h-14 !text-2xl"
                        autofocus
                        required
                    />
                    <flux:error name="temperatureFahrenheit" />
                </flux:field>

                <div class="grid grid-cols-[1fr_auto_1fr] items-end gap-3">
                    <flux:field>
                        <flux:label class="text-base">{{ __('BP Systolic') }}</flux:label>
                        <flux:input
                            wire:model="bpSystolic"
                            type="number"
                            inputmode="numeric"
                            min="50"
                            max="300"
                            class="!h-14 !text-2xl"
                            required
                        />
                        <flux:error name="bpSystolic" />
                    </flux:field>
                    <span class="pb-4 text-2xl font-light text-zinc-400">/</span>

                    <flux:field>
                        <flux:label class="text-base">{{ __('BP Diastolic') }}</flux:label>
                        <flux:input
                            wire:model="bpDiastolic"
                            type="number"
                            inputmode="numeric"
                            min="30"
                            max="200"
                            class="!h-14 !text-2xl"
                            required
                        />
                        <flux:error name="bpDiastolic" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label class="text-base">{{ __('BSR (mg/dL)') }}</flux:label>
                    <flux:input
                        wire:model="bsr"
                        type="number"
                        inputmode="numeric"
                        min="20"
                        max="600"
                        class="!h-14 !text-2xl"
                    />
                    <flux:error name="bsr" />
                </flux:field>

                <div class="flex flex-col gap-3 pt-2">
                    <flux:button type="submit" variant="primary" class="h-14 w-full text-lg font-semibold">
                        {{ __('Next') }}
                    </flux:button>
                    <flux:button type="button" variant="ghost" wire:click="backToList" class="w-full">
                        {{ __('Back to list') }}
                    </flux:button>
                </div>
            </form>
        </x-paper-slip>
    @endif
</div>
