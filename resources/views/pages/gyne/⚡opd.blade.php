<?php

use App\Livewire\Concerns\InteractsWithGyneHistory;
use App\Models\GyneHistory;
use App\Models\QueueToken;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title("Today's OPD")] class extends Component
{
    use InteractsWithGyneHistory;

    public ?int $selectedTokenId = null;

    public bool $showHistoryModal = false;

    public bool $isEditingHistory = false;

    /**
     * Arrived patients (waiting or serving) in gynecologist queues for the latest shift.
     *
     * Gynecologists see their own queues; admins see every gynecologist's queue.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function patients(): Collection
    {
        $user = auth()->user();

        return $this->gyneOpdTokens($user->isAdmin() ? null : $user->doctor?->id);
    }

    /**
     * The patient whose history is open.
     */
    #[Computed]
    public function selectedToken(): ?QueueToken
    {
        if ($this->selectedTokenId === null) {
            return null;
        }

        return $this->patients->firstWhere('id', $this->selectedTokenId);
    }

    /**
     * Histories from the selected patient's earlier visits, newest first.
     *
     * @return Collection<int, GyneHistory>
     */
    #[Computed]
    public function previousHistories(): Collection
    {
        $token = $this->selectedToken;

        if ($token === null) {
            return new Collection;
        }

        return GyneHistory::query()
            ->with('queueToken')
            ->where('patient_id', $token->patient_id)
            ->where('queue_token_id', '!=', $token->id)
            ->latest('id')
            ->limit(5)
            ->get();
    }

    /**
     * Open a patient's history.
     */
    public function openHistory(int $tokenId): void
    {
        if ($this->patients->firstWhere('id', $tokenId) === null) {
            Flux::toast(variant: 'danger', text: __('Patient is no longer in the OPD list.'));

            return;
        }

        $this->selectedTokenId = $tokenId;
        $this->isEditingHistory = false;
        $this->showHistoryModal = true;
        unset($this->selectedToken, $this->previousHistories);
    }

    /**
     * Switch the open history into edit mode, prefilled.
     */
    public function editHistory(): void
    {
        $token = $this->selectedToken;

        if ($token === null) {
            return;
        }

        $this->fillGyneHistoryForm($token);
        $this->isEditingHistory = true;
    }

    /**
     * Leave edit mode without saving.
     */
    public function cancelEditHistory(): void
    {
        $this->isEditingHistory = false;
        $this->resetGyneHistoryForm();
    }

    /**
     * Save the doctor's changes to this visit's history.
     */
    public function saveHistory(): void
    {
        $token = $this->selectedToken;

        if ($token === null) {
            $this->closeHistory();

            return;
        }

        $this->saveGyneHistory($token);

        $this->isEditingHistory = false;
        unset($this->patients, $this->selectedToken);

        Flux::toast(variant: 'success', text: __('History saved.'));
    }

    /**
     * Close the history modal.
     */
    public function closeHistory(): void
    {
        $this->showHistoryModal = false;
        $this->isEditingHistory = false;
        $this->selectedTokenId = null;
        $this->resetGyneHistoryForm();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between gap-3">
        <div>
            <flux:heading level="1">{{ __("Today's OPD") }}</flux:heading>
            <flux:text class="text-zinc-500">{{ __('Patients who have arrived and are waiting to be seen.') }}</flux:text>
        </div>
        <flux:badge color="rose" size="lg">{{ $this->patients->count() }}</flux:badge>
    </div>

    <div @unless ($showHistoryModal) wire:poll.10s @endunless class="grid flex-1 grid-cols-1 content-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->patients as $token)
            <x-paper-slip
                as="button"
                type="button"
                wire:key="gyne-opd-token-{{ $token->id }}"
                wire:click="openHistory({{ $token->id }})"
                :token="$token->token_number"
                class="min-h-40 active:scale-[0.99] hover:-translate-y-0.5"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <x-patient-phone-indicator :patient="$token->patient" />
                    <p class="truncate text-lg font-semibold text-zinc-900">
                        {{ $token->patient?->name ?? __('Unknown') }}
                    </p>
                </div>
                <p class="truncate text-xs uppercase tracking-wide text-zinc-500">
                    {{ $token->patient?->mrn ?? __('No MRN') }}
                    @if ($token->patient?->age)
                        · {{ $token->patient->age }}
                    @endif
                    · {{ $token->serviceQueue?->service?->name }}
                </p>
                @if ($token->patient?->husband_name)
                    <p class="truncate text-xs text-zinc-600">{{ __('W/o :name', ['name' => $token->patient->husband_name]) }}</p>
                @endif
                @if (auth()->user()->isAdmin() && $token->serviceQueue?->doctor)
                    <p class="truncate text-xs text-zinc-600">{{ $token->serviceQueue->doctor->name }}</p>
                @endif

                @if ($token->vital)
                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 border-t border-dashed border-zinc-400/70 pt-2 text-xs text-zinc-700">
                        @if ($token->vital->bp_systolic && $token->vital->bp_diastolic)
                            <span>{{ __('BP') }} {{ $token->vital->bp_systolic }}/{{ $token->vital->bp_diastolic }}</span>
                        @endif
                        @if ($token->vital->temperature)
                            <span>{{ __('Temp') }} {{ $token->vital->temperature }}°F</span>
                        @endif
                        @if ($token->vital->bsr)
                            <span>{{ __('BSR') }} {{ $token->vital->bsr }}</span>
                        @endif
                    </div>
                @endif

                <div class="mt-1 border-t border-dashed border-zinc-400/70 pt-2">
                    @if ($token->gyneHistory)
                        <x-gyne-history-summary :history="$token->gyneHistory" compact />
                    @else
                        <p class="text-xs italic text-zinc-500">{{ __('History not taken yet') }}</p>
                    @endif
                </div>

                <div class="mt-auto flex items-center justify-between gap-2 pt-2">
                    <span class="text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-400">
                        {{ __('Arrived :time', ['time' => $token->arrived_at->format('h:i A')]) }}
                        · {{ $token->arrived_at->diffForHumans(short: true) }}
                    </span>
                    @if ($token->status === 'serving')
                        <flux:badge size="sm" color="blue">{{ __('Serving') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="amber">{{ __('Waiting') }}</flux:badge>
                    @endif
                </div>
            </x-paper-slip>
        @empty
            <div class="col-span-full flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-600">
                <flux:icon name="heart" class="size-10 text-zinc-400" />
                <p class="text-base font-medium text-zinc-700 dark:text-zinc-200">{{ __('No patients have arrived') }}</p>
                <p class="text-sm text-zinc-500">{{ __('Patients appear here once they arrive for a gynecologist\'s queue.') }}</p>
            </div>
        @endforelse
    </div>

    <flux:modal name="gyne-history" wire:model="showHistoryModal" @close="$wire.closeHistory()" class="w-full max-w-xl">
        @php($token = $this->selectedToken)
        @if ($token)
            <div class="flex flex-col gap-5">
                <div class="flex items-center gap-3 pe-8">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-zinc-900 font-bold text-white dark:bg-white dark:text-zinc-900">
                        {{ $token->token_number }}
                    </span>
                    <div class="min-w-0">
                        <flux:heading size="lg" class="truncate">{{ $token->patient?->name }}</flux:heading>
                        <flux:text class="truncate">
                            {{ $token->patient?->mrn }}
                            @if ($token->patient?->age)
                                · {{ $token->patient->age }}
                            @endif
                            @if ($token->patient?->husband_name)
                                · {{ __('W/o :name', ['name' => $token->patient->husband_name]) }}
                            @endif
                        </flux:text>
                    </div>
                </div>

                @if ($isEditingHistory)
                    <form wire:submit="saveHistory" class="flex flex-col gap-4">
                        <x-gyne-history-fields :lmp-preview="$this->lmpPreview()" />
                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" wire:click="cancelEditHistory">{{ __('Cancel') }}</flux:button>
                            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                        </div>
                    </form>
                @else
                    <div class="flex flex-col gap-3">
                        <div class="flex items-center justify-between gap-2">
                            <flux:heading>{{ __("Today's history") }}</flux:heading>
                            <flux:button size="sm" icon="pencil-square" wire:click="editHistory">
                                {{ $token->gyneHistory ? __('Edit') : __('Add') }}
                            </flux:button>
                        </div>
                        @if ($token->gyneHistory)
                            <x-gyne-history-summary :history="$token->gyneHistory" />
                            <flux:text class="text-xs">
                                {{ __('Taken by :name at :time', [
                                    'name' => $token->gyneHistory->recordedBy?->name ?? __('unknown'),
                                    'time' => $token->gyneHistory->created_at->format('h:i A'),
                                ]) }}
                            </flux:text>
                        @else
                            <flux:text>{{ __('The assistant has not taken this patient\'s history yet.') }}</flux:text>
                        @endif
                    </div>

                    @if ($this->previousHistories->isNotEmpty())
                        <div class="flex flex-col gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <flux:heading>{{ __('Previous visits') }}</flux:heading>
                            @foreach ($this->previousHistories as $previous)
                                <div wire:key="gyne-previous-{{ $previous->id }}" class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                        {{ ($previous->queueToken?->arrived_at ?? $previous->created_at)->format('d M Y') }}
                                    </p>
                                    <x-gyne-history-summary :history="$previous" />
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        @endif
    </flux:modal>
</div>
