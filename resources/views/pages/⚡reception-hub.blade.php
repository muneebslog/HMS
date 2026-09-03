<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reception')] class extends Component
{
    /**
     * Restrict the Reception hub to admins.
     */
    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        @php
            $receptionCards = [
                [
                    'label' => __('Shift'),
                    'description' => __('Open and close reception shifts'),
                    'icon' => 'clock',
                    'href' => route('reception.shift'),
                    'icon_bg' => 'bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400',
                ],
                [
                    'label' => __('Reservations'),
                    'description' => __('Manage patient reservations'),
                    'icon' => 'calendar-days',
                    'href' => route('reception.reservation'),
                    'icon_bg' => 'bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400',
                ],
                [
                    'label' => __('Walk-in'),
                    'description' => __('Register walk-in patients'),
                    'icon' => 'user-plus',
                    'href' => route('reception.walkin'),
                    'icon_bg' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400',
                ],
                [
                    'label' => __('Procedures'),
                    'description' => __('Procedure admissions and billing'),
                    'icon' => 'clipboard-document-list',
                    'href' => route('reception.procedures'),
                    'icon_bg' => 'bg-violet-100 text-violet-600 dark:bg-violet-900/30 dark:text-violet-400',
                ],
                [
                    'label' => __('Lab Entry'),
                    'description' => __('Create and manage lab entries'),
                    'icon' => 'beaker',
                    'href' => route('reception.lab-entry'),
                    'icon_bg' => 'bg-cyan-100 text-cyan-600 dark:bg-cyan-900/30 dark:text-cyan-400',
                ],
            ];
        @endphp

        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Reception') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    {{ __('Front desk tools for shifts, intake, procedures, and lab entry.') }}
                </flux:text>
            </div>

            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('dashboard')" wire:navigate>
                {{ __('Back to Dashboard') }}
            </flux:button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($receptionCards as $card)
                <a href="{{ $card['href'] }}" wire:navigate wire:key="reception-hub-{{ $loop->index }}" class="block">
                    <flux:card class="group flex h-full min-h-28 items-center gap-4 transition duration-150 hover:-translate-y-0.5 hover:shadow-md">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full {{ $card['icon_bg'] }} transition duration-150 group-hover:scale-105">
                            <flux:icon :name="$card['icon']" class="size-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <flux:heading level="3" class="text-base">{{ $card['label'] }}</flux:heading>
                            <flux:text class="mt-0.5 text-sm text-zinc-500">{{ $card['description'] }}</flux:text>
                        </div>
                        <flux:icon name="chevron-right" class="size-4 shrink-0 text-zinc-400 transition duration-150 group-hover:translate-x-0.5" />
                    </flux:card>
                </a>
            @endforeach
        </div>
    </div>
</div>
