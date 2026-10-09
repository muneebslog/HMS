<?php

use App\Models\Station;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Seconds after which the client treats the shown status as stale (missed polls).
     */
    public const STALE_AFTER_SECONDS = 45;

    /**
     * Registered, active station PCs that show the ER medication list.
     *
     * @return Collection<int, Station>
     */
    #[Computed]
    public function erStations(): Collection
    {
        return Station::query()
            ->where('is_active', true)
            ->whereNotNull('device_token_hash')
            ->orderBy('name')
            ->get()
            ->filter(fn (Station $station): bool => $station->canOpen('display.medication'))
            ->values();
    }
};
?>

@php
    $stations = $this->erStations;
    $isAnyOnline = $stations->contains(fn (\App\Models\Station $station): bool => $station->isOnline());
@endphp

<div
    wire:poll.15s.keep-alive
    x-data="{
        renderedAt: Date.now(),
        now: Date.now(),
        timer: null,
        init() { this.timer = setInterval(() => this.now = Date.now(), 5000) },
        destroy() { clearInterval(this.timer) },
    }"
    data-test="er-station-status"
    class="in-data-flux-sidebar-collapsed-desktop:hidden mx-1 mt-2 rounded-lg border px-3 py-2 text-xs {{ $stations->isEmpty() ? 'border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800' : ($isAnyOnline ? 'border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950/40' : 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/40') }}"
>
    {{-- A new key on every server render swaps this node, re-running x-init to mark the status fresh. --}}
    <span hidden wire:key="er-status-rendered-{{ now()->getTimestampMs() }}" x-init="renderedAt = Date.now(); now = Date.now()"></span>

    <div x-show="now - renderedAt >{{ self::STALE_AFTER_SECONDS * 1000 }}" x-cloak class="flex items-center gap-2 font-semibold text-amber-700 dark:text-amber-400">
        <span class="size-2 shrink-0 rounded-full bg-amber-500"></span>
        {{ __('ER PC status unknown — check your connection') }}
    </div>

    <div x-show="now - renderedAt <= {{ self::STALE_AFTER_SECONDS * 1000 }}">
        @if ($stations->isEmpty())
            <div class="flex items-center gap-2 font-semibold text-zinc-600 dark:text-zinc-300">
                <span class="size-2 shrink-0 rounded-full bg-zinc-400"></span>
                {{ __('No ER PC registered') }}
            </div>
        @else
            <div class="flex items-center gap-2 font-semibold {{ $isAnyOnline ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                <span class="relative flex size-2 shrink-0">
                    @if ($isAnyOnline)
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-green-400 opacity-75"></span>
                    @endif
                    <span class="relative inline-flex size-2 rounded-full {{ $isAnyOnline ? 'bg-green-500' : 'bg-red-500' }}"></span>
                </span>
                {{ $isAnyOnline ? __('ER PC online') : __('ER PC offline') }}
            </div>

            <ul class="mt-1 space-y-0.5 text-zinc-600 dark:text-zinc-400">
                @foreach ($stations as $station)
                    <li class="flex items-center justify-between gap-2" title="{{ $station->last_seen_at?->format('d M Y, h:i:s A') }}">
                        <span class="truncate">{{ $station->name }}</span>
                        <span class="shrink-0 {{ $station->isOnline() ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                            @if ($station->isOnline())
                                {{ __('Online') }}
                            @elseif ($station->last_seen_at)
                                {{ __('Last seen :time', ['time' => $station->last_seen_at->diffForHumans(short: true)]) }}
                            @else
                                {{ __('Never seen') }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>

            @unless ($isAnyOnline)
                <p class="mt-1 text-red-700 dark:text-red-400">{{ __('ER cannot see new medication until this PC is back online. Inform ER staff.') }}</p>
            @endunless
        @endif
    </div>
</div>
