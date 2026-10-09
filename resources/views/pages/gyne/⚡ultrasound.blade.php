<?php

use App\Livewire\Concerns\InteractsWithGyneHistory;
use App\Livewire\Concerns\InteractsWithGyneUltrasound;
use App\Models\QueueToken;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Gyne Ultrasound')] class extends Component
{
    use InteractsWithGyneHistory;
    use InteractsWithGyneUltrasound;

    public ?int $selectedTokenId = null;

    /**
     * Arrived patients across every gynecologist's queue.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function patients(): Collection
    {
        return $this->gyneOpdTokens();
    }

    /**
     * The patient whose ultrasound report is being entered.
     */
    #[Computed]
    public function selectedToken(): ?QueueToken
    {
        if ($this->selectedTokenId === null) {
            return null;
        }

        return $this->patients->firstWhere('id', $this->selectedTokenId)
            ?? QueueToken::with(['patient.family', 'serviceQueue.service', 'serviceQueue.doctor', 'gyneHistory', 'gyneUltrasound'])
                ->find($this->selectedTokenId);
    }

    /**
     * Open the ultrasound form for a patient on the list.
     */
    public function selectToken(int $tokenId): void
    {
        $token = $this->patients->firstWhere('id', $tokenId);

        if ($token === null) {
            Flux::toast(variant: 'danger', text: __('Patient is no longer in the OPD list.'));

            return;
        }

        $this->selectedTokenId = $tokenId;
        unset($this->selectedToken);
        $this->fillGyneUltrasoundForm($token);
    }

    /**
     * Return to the list without saving.
     */
    public function backToList(): void
    {
        $this->selectedTokenId = null;
        $this->resetGyneUltrasoundForm();
    }

    /**
     * Save this patient's ultrasound report and open the next patient still pending.
     */
    public function saveAndNext(): void
    {
        $token = $this->selectedToken;

        if ($token === null) {
            $this->backToList();

            return;
        }

        $this->saveGyneUltrasound($token);

        Flux::toast(variant: 'success', text: __('Ultrasound saved for :name.', ['name' => $token->patient?->name ?? __('patient')]));

        unset($this->patients);

        $nextToken = $this->patients
            ->first(fn (QueueToken $candidate) => $candidate->gyneUltrasound === null && $candidate->id !== $token->id);

        if ($nextToken === null) {
            $this->backToList();

            return;
        }

        $this->selectToken($nextToken->id);
    }
}; ?>

<div class="mx-auto flex h-full w-full max-w-lg flex-1 flex-col gap-4">
    @if ($selectedTokenId === null)
        <div class="flex items-center justify-between gap-3">
            <div>
                <flux:heading level="1">{{ __('Gyne Ultrasound') }}</flux:heading>
                <flux:text class="text-zinc-500">{{ __('Tap a patient to enter her ultrasound report.') }}</flux:text>
            </div>
            <flux:badge color="sky" size="lg">
                {{ $this->patients->whereNull('gyneUltrasound')->count() }} / {{ $this->patients->count() }}
            </flux:badge>
        </div>

        <div wire:poll.10s class="flex flex-col gap-3">
            @forelse ($this->patients as $token)
                <button
                    type="button"
                    wire:key="gyne-ultrasound-token-{{ $token->id }}"
                    wire:click="selectToken({{ $token->id }})"
                    class="flex w-full items-center gap-3 rounded-xl border border-zinc-200 bg-white p-3 text-left shadow-xs active:scale-[0.99] dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-lg font-bold text-white dark:bg-white dark:text-zinc-900">
                        {{ $token->token_number }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-zinc-900 dark:text-white">{{ $token->patient?->name ?? __('Unknown') }}</span>
                        <span class="block truncate text-xs text-zinc-500">
                            {{ $token->patient?->mrn ?? __('No MRN') }} · {{ $token->serviceQueue?->doctor?->name }}
                        </span>
                        @if ($token->gyneHistory?->gestationalAgeLabel())
                            <span class="mt-0.5 block truncate text-[10px] font-semibold uppercase tracking-[0.18em] text-rose-500">
                                {{ __('LMP :age', ['age' => $token->gyneHistory->gestationalAgeLabel()]) }}
                            </span>
                        @endif
                    </span>
                    <span class="flex shrink-0 flex-col items-end gap-1">
                        @if ($token->gyneUltrasound)
                            <flux:badge size="sm" color="green" icon="check">{{ __('Done') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                        @endif
                        @unless ($token->gyneHistory)
                            <flux:badge size="sm" color="zinc">{{ __('No history') }}</flux:badge>
                        @endunless
                    </span>
                </button>
            @empty
                <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-600">
                    <flux:icon name="heart" class="size-10 text-zinc-400" />
                    <p class="text-base font-medium text-zinc-700 dark:text-zinc-200">{{ __('No patients have arrived') }}</p>
                    <p class="text-sm text-zinc-500">{{ __('Patients appear here once they arrive for a gynecologist\'s queue.') }}</p>
                </div>
            @endforelse
        </div>
    @else
        @php($token = $this->selectedToken)
        <div class="sticky top-0 z-10 -mx-4 flex items-center gap-3 border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900 sm:mx-0 sm:rounded-xl sm:border">
            <flux:button variant="ghost" icon="arrow-left" wire:click="backToList" :aria-label="__('Back to list')" />
            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-zinc-900 font-bold text-white dark:bg-white dark:text-zinc-900">
                {{ $token?->token_number }}
            </span>
            <div class="min-w-0">
                <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $token?->patient?->name }}</p>
                <p class="truncate text-xs text-zinc-500">
                    {{ $token?->patient?->mrn }}
                    @if ($token?->patient?->husband_name)
                        · {{ __('W/o :name', ['name' => $token->patient->husband_name]) }}
                    @endif
                </p>
            </div>
        </div>

        <form wire:submit="saveAndNext" class="flex flex-col gap-4">
            <x-gyne-ultrasound-fields
                :edd-preview="$this->ultrasoundEddPreview()"
                :lmp-gestation="$token?->gyneHistory?->gestationalAgeLabel()"
            />

            <div class="sticky bottom-0 z-20 -mx-4 border-t border-zinc-200 bg-white/95 p-3 backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95 sm:mx-0 sm:rounded-xl sm:border">
                <flux:button type="submit" variant="primary" class="w-full" icon-trailing="arrow-right">
                    {{ __('Save & next') }}
                </flux:button>
            </div>
        </form>
    @endif
</div>
