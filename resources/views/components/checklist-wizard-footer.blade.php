@props([
    'isFirst' => false,
    'isLast' => false,
    'answered' => 0,
    'total' => 0,
    'saveLabel' => null,
])

@php
    $saveLabel ??= __('Save');
@endphp

<div {{ $attributes->class('sticky bottom-0 z-10 rounded-xl border border-zinc-200 bg-white/95 p-4 shadow-sm backdrop-blur-sm dark:border-zinc-700 dark:bg-zinc-900/95') }}>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-col gap-1">
            <div class="flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                <flux:icon name="chart-pie" class="size-4 text-primary" />
                {{ __(':answered of :total completed', ['answered' => $answered, 'total' => $total]) }}
            </div>
            <flux:text class="text-xs text-zinc-500">
                @if ($isLast)
                    {{ __('Review this last section, then save the checklist.') }}
                @else
                    {{ __('Complete this section, then continue to the next step.') }}
                @endif
            </flux:text>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2">
            @unless ($isFirst)
                <flux:button type="button" variant="ghost" icon="arrow-left" wire:click="previousSection">
                    {{ __('Back') }}
                </flux:button>
            @endunless

            @if ($isLast)
                <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">
                    {{ $saveLabel }}
                </flux:button>
            @else
                <flux:button type="button" variant="primary" icon="arrow-right" wire:click="nextSection">
                    {{ __('Next') }}
                </flux:button>
            @endif
        </div>
    </div>
</div>
