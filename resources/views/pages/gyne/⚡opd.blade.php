<?php

use App\Models\QueueToken;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title("Today's OPD")] class extends Component
{
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
        $shift = Shift::current() ?? Shift::query()->latest('opened_at')->first();

        if ($shift === null) {
            return new Collection;
        }

        $user = auth()->user();
        $doctorId = $user->isAdmin() ? null : $user->doctor?->id;

        return QueueToken::query()
            ->with(['patient.family', 'serviceQueue.service', 'serviceQueue.doctor', 'vital'])
            ->whereNotNull('arrived_at')
            ->whereIn('status', ['waiting', 'serving'])
            ->whereHas('serviceQueue', function (Builder $queueQuery) use ($shift, $doctorId): void {
                $queueQuery->forShift($shift)
                    ->when(
                        $doctorId !== null,
                        fn (Builder $query) => $query->where('doctor_id', $doctorId),
                        fn (Builder $query) => $query->whereHas('doctor', fn (Builder $doctorQuery) => $doctorQuery->gynecologists()),
                    );
            })
            ->orderBy('arrived_at')
            ->orderBy('token_number')
            ->get();
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

    <div wire:poll.10s class="grid flex-1 grid-cols-1 content-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->patients as $token)
            <x-paper-slip
                wire:key="gyne-opd-token-{{ $token->id }}"
                :token="$token->token_number"
                class="min-h-40"
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
</div>
