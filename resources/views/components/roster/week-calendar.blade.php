@props([
    'weekDays',
    'segments',
])

@php
    $hours = range(0, 23);
    $segmentsByDay = collect($segments)->groupBy('day');
    $templateColors = [
        'Morning' => 'bg-sky-100 border-sky-400 text-sky-900 dark:bg-sky-950 dark:border-sky-600 dark:text-sky-100',
        'Evening' => 'bg-amber-100 border-amber-400 text-amber-900 dark:bg-amber-950 dark:border-amber-600 dark:text-amber-100',
        'Night' => 'bg-violet-100 border-violet-400 text-violet-900 dark:bg-violet-950 dark:border-violet-600 dark:text-violet-100',
    ];
@endphp

<div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
    <div class="min-w-[56rem]">
        <div class="grid border-b border-zinc-200 dark:border-zinc-700" style="grid-template-columns: 4rem repeat(7, minmax(0, 1fr));">
            <div class="border-r border-zinc-200 px-2 py-3 text-xs font-medium text-zinc-500 dark:border-zinc-700"></div>
            @foreach ($weekDays as $day)
                <div
                    wire:key="week-header-{{ $day->toDateString() }}"
                    class="border-r border-zinc-200 px-2 py-3 text-center last:border-r-0 dark:border-zinc-700 {{ $day->isToday() ? 'bg-zinc-50 dark:bg-zinc-800/60' : '' }}"
                >
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $day->format('D') }}</div>
                    <div class="text-sm font-semibold {{ $day->isToday() ? 'text-sky-600 dark:text-sky-400' : '' }}">{{ $day->format('M j') }}</div>
                </div>
            @endforeach
        </div>

        <div class="relative grid" style="grid-template-columns: 4rem repeat(7, minmax(0, 1fr));">
            <div class="border-r border-zinc-200 dark:border-zinc-700">
                @foreach ($hours as $hour)
                    <div
                        wire:key="hour-label-{{ $hour }}"
                        class="flex h-12 items-start justify-end border-b border-zinc-100 pr-2 pt-1 text-xs text-zinc-400 dark:border-zinc-800"
                    >
                        {{ sprintf('%02d:00', $hour) }}
                    </div>
                @endforeach
            </div>

            @foreach ($weekDays as $day)
                @php
                    $dayString = $day->toDateString();
                @endphp
                <div
                    wire:key="week-column-{{ $dayString }}"
                    class="relative border-r border-zinc-200 last:border-r-0 dark:border-zinc-700 {{ $day->isToday() ? 'bg-zinc-50/50 dark:bg-zinc-800/30' : '' }}"
                >
                    @foreach ($hours as $hour)
                        <button
                            type="button"
                            wire:key="slot-{{ $dayString }}-{{ $hour }}"
                            wire:click="openOverrideModal('{{ $dayString }}', {{ $hour }})"
                            class="block h-12 w-full border-b border-zinc-100 transition hover:bg-sky-50/60 dark:border-zinc-800 dark:hover:bg-sky-950/30"
                            aria-label="{{ __('Assign duty on :date at :time', ['date' => $day->format('M j'), 'time' => sprintf('%02d:00', $hour)]) }}"
                        ></button>
                    @endforeach

                    @foreach ($segmentsByDay->get($dayString, collect()) as $segment)
                        @php
                            /** @var \App\Models\DutyAssignment $assignment */
                            $assignment = $segment['assignment'];
                            $templateName = $assignment->shiftTemplate?->name;
                            $colorClass = $templateColors[$templateName] ?? 'bg-emerald-100 border-emerald-400 text-emerald-900 dark:bg-emerald-950 dark:border-emerald-600 dark:text-emerald-100';
                        @endphp
                        <button
                            type="button"
                            wire:key="segment-{{ $segment['segment_key'] }}"
                            wire:click.stop="openEditOverride({{ $assignment->id }})"
                            class="absolute inset-x-1 z-10 overflow-hidden rounded-md border px-1.5 py-1 text-left text-xs shadow-sm {{ $colorClass }}"
                            style="top: {{ $segment['top_percent'] }}%; height: {{ max($segment['height_percent'], 2.5) }}%;"
                        >
                            <div class="truncate font-semibold">{{ $assignment->healthAide->name }}</div>
                            <div class="truncate opacity-80">
                                {{ $assignment->starts_at->format('H:i') }}–{{ $assignment->ends_at->format('H:i') }}
                                @if ($assignment->ends_at->toDateString() !== $assignment->starts_at->toDateString())
                                    <span class="font-medium">+1</span>
                                @endif
                            </div>
                            @if ($assignment->dutyLocation)
                                <div class="truncate opacity-80">{{ $assignment->dutyLocation->name }}</div>
                            @endif
                            @if ($assignment->is_override)
                                <flux:badge size="sm" class="mt-0.5">{{ __('Override') }}</flux:badge>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</div>
