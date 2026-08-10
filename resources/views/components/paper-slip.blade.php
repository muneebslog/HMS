@props([
    'token' => null,
    'tone' => 'default',
    'as' => 'div',
])

@php
    $toneClasses = match ($tone) {
        'accent' => 'border-amber-500 ring-2 ring-amber-400/40',
        default => 'border-zinc-300/90',
    };
@endphp

<{{ $as }}
    {{ $attributes->class([
        'paper-slip group relative flex w-full flex-col overflow-hidden rounded-sm border bg-[#f7f4ec] text-left text-zinc-900 shadow-[0_1px_0_rgba(255,255,255,0.85)_inset,0_1px_2px_rgba(0,0,0,0.06),0_10px_24px_rgba(0,0,0,0.12)] transition duration-150',
        $toneClasses,
    ]) }}
>
    <span
        class="pointer-events-none absolute inset-x-3 top-0 h-2.5 bg-repeat-x"
        style="background-image: radial-gradient(circle, var(--paper-slip-hole, #0a0a0a) 3.5px, transparent 4px); background-size: 14px 10px; background-position: center -5px;"
        aria-hidden="true"
    ></span>

    @if ($token !== null)
        <div class="border-b border-dashed border-zinc-400/70 px-4 pb-3 pt-5 text-center">
            <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-zinc-500">{{ __('Token') }}</p>
            <p class="mt-1 font-mono text-4xl font-bold leading-none tabular-nums tracking-tight text-zinc-900">
                {{ $token }}
            </p>
        </div>
    @endif

    <div @class(['flex flex-1 flex-col gap-2 px-4', $token !== null ? 'py-3' : 'pb-3 pt-5'])>
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="mt-auto border-t border-dashed border-zinc-400/70 px-4 py-3">
            {{ $footer }}
        </div>
    @endisset
</{{ $as }}>
