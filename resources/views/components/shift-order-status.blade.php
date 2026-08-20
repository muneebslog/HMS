@props([
    'status' => 'not_given',
    'label' => null,
])

@php
    $classes = match ($status) {
        'given' => 'bg-emerald-100 text-emerald-800',
        'waiting' => 'bg-amber-100 text-amber-800',
        default => 'bg-zinc-200 text-zinc-700',
    };
@endphp

<span {{ $attributes->class(['shrink-0 rounded-sm px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide', $classes]) }}>
    {{ $label ?? $status }}
</span>
