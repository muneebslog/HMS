@props([
    'centerTitle',
    'centerSubtitle' => null,
    'centerIcon' => 'home',
    'nodes' => [],
])

@php
    $nodeCount = max(count($nodes), 1);
@endphp

{{-- Mobile: simple stacked cards --}}
<div {{ $attributes->class('md:hidden') }}>
    <div class="mb-4 flex items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
            <flux:icon :name="$centerIcon" class="size-5" />
        </div>
        <div class="min-w-0">
            <flux:heading level="2" class="text-base">{{ $centerTitle }}</flux:heading>
            @if ($centerSubtitle)
                <flux:text class="text-sm text-zinc-500">{{ $centerSubtitle }}</flux:text>
            @endif
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ($nodes as $index => $node)
            @php
                $cardClasses = 'group flex min-h-24 items-center gap-3 transition duration-150 hover:-translate-y-0.5 hover:shadow-md';
            @endphp

            @if (! empty($node['href']))
                <a href="{{ $node['href'] }}" wire:navigate wire:key="mind-map-mobile-{{ $index }}" class="block">
                    <flux:card class="{{ $cardClasses }}">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $node['icon_bg'] }}">
                            <flux:icon :name="$node['icon']" class="size-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <flux:heading level="3" class="text-sm">{{ $node['label'] }}</flux:heading>
                            @if (! empty($node['description']))
                                <flux:text class="mt-0.5 text-xs text-zinc-500">{{ $node['description'] }}</flux:text>
                            @endif
                        </div>
                        <flux:icon name="chevron-right" class="size-4 shrink-0 text-zinc-400" />
                    </flux:card>
                </a>
            @else
                <flux:card wire:key="mind-map-mobile-{{ $index }}" class="{{ $cardClasses }}">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $node['icon_bg'] }}">
                        <flux:icon :name="$node['icon']" class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <flux:heading level="3" class="text-sm">{{ $node['label'] }}</flux:heading>
                        @if (! empty($node['description']))
                            <flux:text class="mt-0.5 text-xs text-zinc-500">{{ $node['description'] }}</flux:text>
                        @endif
                    </div>
                </flux:card>
            @endif
        @endforeach
    </div>
</div>

{{-- Desktop: radial mind map with connector lines --}}
<div class="relative mx-auto hidden aspect-square w-full max-w-4xl md:block lg:max-w-5xl">
    <svg class="pointer-events-none absolute inset-0 size-full" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
        @foreach ($nodes as $index => $node)
            @php
                $angle = ((360 / $nodeCount) * $index) - 90;
                $radians = deg2rad($angle);
                $radius = 36;
                $x2 = 50 + (cos($radians) * $radius);
                $y2 = 50 + (sin($radians) * $radius);
            @endphp
            <line
                x1="50"
                y1="50"
                x2="{{ number_format($x2, 2, '.', '') }}"
                y2="{{ number_format($y2, 2, '.', '') }}"
                class="stroke-zinc-300 dark:stroke-zinc-600"
                stroke-width="0.4"
                stroke-linecap="round"
            />
            <circle
                cx="{{ number_format($x2, 2, '.', '') }}"
                cy="{{ number_format($y2, 2, '.', '') }}"
                r="0.7"
                class="fill-zinc-300 dark:fill-zinc-600"
            />
        @endforeach
        <circle cx="50" cy="50" r="1" class="fill-zinc-400 dark:fill-zinc-500" />
    </svg>

    <div class="absolute top-1/2 left-1/2 z-20 w-44 -translate-x-1/2 -translate-y-1/2 lg:w-52">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 text-center shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                <flux:icon :name="$centerIcon" class="size-6" />
            </div>
            <flux:heading level="2" class="text-base lg:text-lg">{{ $centerTitle }}</flux:heading>
            @if ($centerSubtitle)
                <flux:text class="mt-1 text-xs text-zinc-500 lg:text-sm">{{ $centerSubtitle }}</flux:text>
            @endif
        </div>
    </div>

    @foreach ($nodes as $index => $node)
        @php
            $angle = ((360 / $nodeCount) * $index) - 90;
            $radians = deg2rad($angle);
            $radius = 36;
            $x = 50 + (cos($radians) * $radius);
            $y = 50 + (sin($radians) * $radius);
            $cardClasses = 'group w-44 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition duration-150 hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800 lg:w-48';
        @endphp

        <div
            class="absolute z-10 -translate-x-1/2 -translate-y-1/2"
            style="left: {{ number_format($x, 2, '.', '') }}%; top: {{ number_format($y, 2, '.', '') }}%;"
            wire:key="mind-map-node-{{ $index }}"
        >
            @if (! empty($node['href']))
                <a href="{{ $node['href'] }}" wire:navigate class="block">
                    <div class="{{ $cardClasses }}">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $node['icon_bg'] }} transition duration-150 group-hover:scale-105">
                                <flux:icon :name="$node['icon']" class="size-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <flux:heading level="3" class="text-sm">{{ $node['label'] }}</flux:heading>
                                    <flux:icon name="chevron-right" class="mt-0.5 size-3.5 shrink-0 text-zinc-400 transition duration-150 group-hover:translate-x-0.5" />
                                </div>
                                @if (! empty($node['description']))
                                    <flux:text class="mt-1 text-xs leading-snug text-zinc-500">{{ $node['description'] }}</flux:text>
                                @endif
                            </div>
                        </div>
                    </div>
                </a>
            @else
                <div class="{{ $cardClasses }}">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $node['icon_bg'] }}">
                            <flux:icon :name="$node['icon']" class="size-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <flux:heading level="3" class="text-sm">{{ $node['label'] }}</flux:heading>
                            @if (! empty($node['description']))
                                <flux:text class="mt-1 text-xs leading-snug text-zinc-500">{{ $node['description'] }}</flux:text>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>
