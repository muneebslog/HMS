@props([
    'model',
    'label',
    'hint' => null,
])

{{-- Big −/+ counter for touch screens. Empty means "not asked yet"; − from empty sets 0. --}}
<div class="flex items-center justify-between gap-3 py-2">
    <div class="min-w-0">
        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $label }}</p>
        @if ($hint)
            <p class="text-xs text-zinc-500">{{ $hint }}</p>
        @endif
    </div>
    <div class="flex shrink-0 items-center gap-2">
        <button
            type="button"
            class="flex size-11 items-center justify-center rounded-full border border-zinc-300 text-xl font-semibold text-zinc-700 active:scale-95 dark:border-zinc-600 dark:text-zinc-200"
            aria-label="{{ __('Decrease :label', ['label' => $label]) }}"
            x-on:click="$wire.{{ $model }} = Math.max(0, (Number($wire.{{ $model }}) || 0) - 1)"
        >−</button>
        <span
            class="w-10 text-center text-2xl font-bold tabular-nums text-zinc-900 dark:text-white"
            x-text="$wire.{{ $model }} === null || $wire.{{ $model }} === '' ? '–' : $wire.{{ $model }}"
        >–</span>
        <button
            type="button"
            class="flex size-11 items-center justify-center rounded-full bg-rose-600 text-xl font-semibold text-white active:scale-95"
            aria-label="{{ __('Increase :label', ['label' => $label]) }}"
            x-on:click="$wire.{{ $model }} = Math.min(30, (Number($wire.{{ $model }}) || 0) + 1)"
        >+</button>
    </div>
</div>
<flux:error :name="$model" />
