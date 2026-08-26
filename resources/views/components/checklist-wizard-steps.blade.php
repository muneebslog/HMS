@props([
    'steps' => [],
    'active' => '',
    'completed' => [],
])

@php
    /** @var list<array{key: string, label: string}> $steps */
    /** @var list<string> $completed */
    $total = count($steps);
    $activeIndex = 0;

    foreach ($steps as $index => $step) {
        if ($step['key'] === $active) {
            $activeIndex = $index;
            break;
        }
    }
@endphp

<div {{ $attributes->class('rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800') }}>
    <div class="mb-4 flex items-center justify-between gap-3">
        <div>
            <flux:heading level="2" class="text-base">{{ __('Checklist steps') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500">
                {{ __('Step :current of :total', ['current' => $activeIndex + 1, 'total' => max($total, 1)]) }}
            </flux:text>
        </div>
        <flux:badge size="sm" color="zinc">
            {{ __(':done done', ['done' => count($completed)]) }}
        </flux:badge>
    </div>

    <ol class="flex gap-2 overflow-x-auto pb-1">
        @foreach ($steps as $index => $step)
            @php
                $isActive = $step['key'] === $active;
                $isComplete = in_array($step['key'], $completed, true);
                $isPast = $index < $activeIndex;
            @endphp
            <li class="flex min-w-0 flex-1 items-center gap-2" wire:key="wizard-step-{{ $step['key'] }}">
                <button
                    type="button"
                    wire:click="setSection('{{ $step['key'] }}')"
                    class="group flex w-full min-w-[7.5rem] flex-col items-center gap-2 rounded-lg px-2 py-2 text-center transition {{ $isActive ? 'bg-primary/10 ring-1 ring-primary/30' : 'hover:bg-zinc-50 dark:hover:bg-zinc-700/50' }}"
                >
                    <span
                        @class([
                            'flex size-8 items-center justify-center rounded-full text-sm font-semibold ring-2 transition',
                            'bg-primary text-white ring-primary' => $isActive,
                            'bg-emerald-500 text-white ring-emerald-500' => ! $isActive && $isComplete,
                            'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-zinc-700 dark:text-zinc-200 dark:ring-zinc-600' => ! $isActive && ! $isComplete,
                        ])
                    >
                        @if ($isComplete && ! $isActive)
                            <flux:icon name="check" class="size-4" />
                        @else
                            {{ $index + 1 }}
                        @endif
                    </span>
                    <span
                        @class([
                            'line-clamp-2 text-xs font-medium',
                            'text-primary' => $isActive,
                            'text-emerald-700 dark:text-emerald-300' => ! $isActive && $isComplete,
                            'text-zinc-600 dark:text-zinc-300' => ! $isActive && ! $isComplete,
                        ])
                    >
                        {{ $step['label'] }}
                    </span>
                </button>

                @if ($index < $total - 1)
                    <div
                        @class([
                            'hidden h-0.5 w-4 shrink-0 rounded-full sm:block',
                            'bg-emerald-400' => $isPast || $isComplete,
                            'bg-zinc-200 dark:bg-zinc-600' => ! $isPast && ! $isComplete,
                        ])
                        aria-hidden="true"
                    ></div>
                @endif
            </li>
        @endforeach
    </ol>
</div>
