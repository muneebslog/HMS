@props([
    'name',
    'detail' => null,
    'isSyrup' => false,
    'delivered' => false,
    'selectable' => false,
    'inputValue' => null,
])

@php
    $icon = $isSyrup ? 'beaker' : 'pill';
@endphp

@if ($selectable && ! $delivered)
    <label {{ $attributes->class([
        'mb-2 flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2 text-sm',
        'border-amber-300 bg-amber-50 text-amber-950' => $isSyrup,
        'border-transparent text-zinc-800' => ! $isSyrup,
    ]) }}>
        <input
            type="checkbox"
            value="{{ $inputValue }}"
            {{ $attributes->whereStartsWith('wire:model') }}
            class="mt-0.5 size-5 rounded border-zinc-400 bg-white text-zinc-900"
        >
        <flux:icon :name="$icon" variant="mini" @class([
            'mt-0.5 size-4 shrink-0',
            'text-amber-600' => $isSyrup,
            'text-violet-600' => ! $isSyrup,
        ]) />
        <span>
            {{ $name }}
            @if (filled($detail))
                <span @class([
                    'text-zinc-500' => ! $isSyrup,
                    'text-amber-700/80' => $isSyrup,
                ])>
                    — {{ $detail }}
                </span>
            @endif
        </span>
    </label>
@else
    <div {{ $attributes->class([
        'mb-2 flex items-start gap-2 rounded-lg px-2 py-1.5 text-sm',
        'bg-amber-50 text-amber-950 ring-1 ring-amber-200' => $isSyrup && ! $delivered,
        'text-zinc-400 line-through' => $delivered,
        'text-zinc-800' => ! $delivered && ! $isSyrup,
        'text-amber-700/70 line-through' => $delivered && $isSyrup,
    ]) }}>
        <flux:icon :name="$icon" variant="mini" @class([
            'mt-0.5 size-4 shrink-0',
            'text-amber-600' => $isSyrup,
            'text-violet-600' => ! $isSyrup,
        ]) />
        <p class="min-w-0 flex-1">
            {{ $name }}
            @if (filled($detail))
                <span @class([
                    'text-zinc-500 no-underline' => ! $isSyrup,
                    'text-amber-700/80 no-underline' => $isSyrup,
                ])>
                    — {{ $detail }}
                </span>
            @endif
            @if ($delivered)
                <span class="ms-2 no-underline">{{ __('Delivered') }}</span>
            @endif
            {{ $slot }}
        </p>
    </div>
@endif
