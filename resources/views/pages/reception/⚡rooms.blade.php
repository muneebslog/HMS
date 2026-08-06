<?php

use App\Models\Room;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rooms')] class extends Component
{
    /**
     * Active rooms with their current on-ward admission, if any.
     *
     * @return Collection<int, Room>
     */
    #[Computed]
    public function rooms(): Collection
    {
        return Room::query()
            ->active()
            ->with(['currentAdmission.patient', 'currentAdmission.procedureType'])
            ->orderBy('number')
            ->get();
    }

    /**
     * Count of currently occupied rooms.
     */
    #[Computed]
    public function occupiedCount(): int
    {
        return $this->rooms->filter->isOccupied()->count();
    }

    /**
     * Count of free rooms.
     */
    #[Computed]
    public function freeCount(): int
    {
        return $this->rooms->count() - $this->occupiedCount;
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Rooms') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ __('See which rooms are free and which are occupied.') }}</flux:text>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:badge size="lg" color="green">{{ __(':count free', ['count' => $this->freeCount]) }}</flux:badge>
                <flux:badge size="lg" color="red">{{ __(':count occupied', ['count' => $this->occupiedCount]) }}</flux:badge>
            </div>
        </div>

        @if ($this->rooms->isEmpty())
            <div class="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-600">
                <flux:icon name="home" class="size-10 text-zinc-400" />
                <p class="text-base font-medium text-zinc-700 dark:text-zinc-200">{{ __('No rooms configured') }}</p>
                <p class="text-sm text-zinc-500">{{ __('Add rooms in Management so they appear here.') }}</p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->rooms as $room)
                    @php
                        $admission = $room->currentAdmission;
                        $occupied = $admission !== null;
                    @endphp
                    <div
                        wire:key="room-{{ $room->id }}"
                        class="flex flex-col gap-3 rounded-xl border p-5 shadow-sm {{ $occupied ? 'border-red-200 bg-red-50 dark:border-red-900/50 dark:bg-red-950/30' : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/50 dark:bg-emerald-950/30' }}"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <flux:heading level="3" class="truncate text-base font-semibold">
                                    {{ $room->number }}
                                </flux:heading>
                            </div>
                            @if ($occupied)
                                <flux:badge size="sm" color="red">{{ __('Occupied') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="green">{{ __('Free') }}</flux:badge>
                            @endif
                        </div>

                        @if ($occupied)
                            <div class="space-y-1 text-sm">
                                <div class="font-medium text-zinc-900 dark:text-white">
                                    {{ $admission->patient->name }}
                                </div>
                                <div class="text-xs text-zinc-500">
                                    {{ $admission->patient->mrn ?? __('No MRN') }}
                                </div>
                                <div class="text-xs text-zinc-600 dark:text-zinc-400">
                                    {{ $admission->procedureType?->name ?? $admission->name }}
                                </div>
                                <div class="text-xs text-zinc-500">
                                    {{ __('Admitted :date', ['date' => $admission->admitted_at?->format('d M, g:i A') ?? '-']) }}
                                </div>
                            </div>
                        @else
                            <flux:text class="text-sm text-zinc-500">{{ __('Available for admission') }}</flux:text>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
