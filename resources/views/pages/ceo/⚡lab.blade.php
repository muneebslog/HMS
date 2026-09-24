<?php

use App\Services\CeoLabOverview;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Lab Overview')] class extends Component
{
    #[Url]
    public string $period = 'today';

    public ?string $openStage = null;

    public bool $showStageModal = false;

    /**
     * Keep the period to the ones the page offers.
     */
    public function updatedPeriod(): void
    {
        if (! array_key_exists($this->period, CeoLabOverview::PERIODS)) {
            $this->period = 'today';
        }
    }

    /**
     * The overview service, shared by everything on the page for one render.
     */
    #[Computed]
    public function overview(): CeoLabOverview
    {
        return app(CeoLabOverview::class);
    }

    /**
     * Show the patients in one stage of the board.
     */
    public function showStage(string $stage): void
    {
        $this->openStage = $stage;
        $this->showStageModal = true;
    }

    /**
     * Format an amount of money in rupees.
     */
    public function money(float $amount): string
    {
        return 'Rs '.number_format($amount);
    }

    /**
     * Turn minutes into a short "2d 3h" / "1h 25m" / "12m" label.
     */
    public function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return match (true) {
            $minutes >= 1440 => sprintf('%dd %dh', intdiv($minutes, 1440), intdiv($minutes % 1440, 60)),
            $minutes >= 60 => sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60),
            default => $minutes.'m',
        };
    }
}; ?>

<div wire:poll.60s>
    @php
        $overview = $this->overview;
        $from = $overview->periodStart($period);
        $strip = $overview->todayStrip();
        $stages = $overview->stages();
        $late = $overview->lateTests();
        $money = $overview->money($from);
        $speed = $overview->speed($from);
        $people = $overview->people($from);
        $trends = $overview->trends();
        $busy = $overview->busyHours();
        $periodLabel = __(CeoLabOverview::PERIODS[$period] ?? 'Today');

        $tone = [
            'amber' => ['dot' => 'bg-amber-400', 'text' => 'text-amber-300', 'ring' => 'ring-amber-500/30', 'glow' => 'from-amber-500/15'],
            'orange' => ['dot' => 'bg-orange-400', 'text' => 'text-orange-300', 'ring' => 'ring-orange-500/30', 'glow' => 'from-orange-500/15'],
            'purple' => ['dot' => 'bg-purple-400', 'text' => 'text-purple-300', 'ring' => 'ring-purple-500/30', 'glow' => 'from-purple-500/15'],
            'sky' => ['dot' => 'bg-sky-400', 'text' => 'text-sky-300', 'ring' => 'ring-sky-500/30', 'glow' => 'from-sky-500/15'],
            'rose' => ['dot' => 'bg-rose-400', 'text' => 'text-rose-300', 'ring' => 'ring-rose-500/30', 'glow' => 'from-rose-500/15'],
            'violet' => ['dot' => 'bg-violet-400', 'text' => 'text-violet-300', 'ring' => 'ring-violet-500/30', 'glow' => 'from-violet-500/15'],
        ];

        $revenues = collect($trends['days'])->pluck('revenue');
        $revenueMax = max(1, $revenues->max());
        $points = $revenues->values()->map(fn ($value, $index) => round($index / 29 * 300, 1).','.round(64 - $value / $revenueMax * 58, 1))->implode(' ');
        $testsMax = max(1, collect($trends['days'])->max('tests'));
        $mixTotal = max(1, $money['in_house'] + $money['outsourced']);
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-5 text-zinc-100">
        {{-- Header --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.2em] text-cyan-400">
                    <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-cyan-400 opacity-75"></span><span class="relative inline-flex size-2 rounded-full bg-cyan-400"></span></span>
                    {{ __('Live · Laboratory') }}
                </div>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-white sm:text-3xl">{{ __('Lab Overview') }}</h1>
                <p class="text-sm text-zinc-400">{{ now()->format('l, d F Y · g:i A') }}</p>
            </div>

            <div class="inline-flex rounded-xl bg-zinc-900 p-1 ring-1 ring-zinc-700">
                @foreach (CeoLabOverview::PERIODS as $key => $label)
                    <button type="button" wire:click="$set('period', '{{ $key }}')"
                        class="cursor-pointer rounded-lg px-4 py-1.5 text-sm font-medium transition {{ $period === $key ? 'bg-cyan-500 text-zinc-950 shadow' : 'text-zinc-400 hover:text-white' }}">
                        {{ __($label) }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Today at a glance --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                ['label' => __('Tests today'), 'value' => $strip['tests_today'], 'class' => 'text-white', 'icon' => 'beaker'],
                ['label' => __('Done today'), 'value' => $strip['done_today'], 'class' => 'text-emerald-400', 'icon' => 'check-circle'],
                ['label' => __('In the lab'), 'value' => $strip['in_lab'], 'class' => 'text-violet-300', 'icon' => 'building-office'],
                ['label' => __('Outsourced out'), 'value' => $strip['with_rider'], 'class' => 'text-purple-300', 'icon' => 'truck'],
                ['label' => __('Retakes'), 'value' => $strip['retakes'], 'class' => $strip['retakes'] > 0 ? 'text-rose-300' : 'text-zinc-300', 'icon' => 'arrow-path'],
                ['label' => __('Late'), 'value' => $strip['late'], 'class' => $strip['late'] > 0 ? 'text-red-400' : 'text-emerald-400', 'icon' => 'exclamation-triangle'],
            ] as $stat)
                <div class="rounded-2xl bg-zinc-900 p-4 ring-1 ring-zinc-800">
                    <div class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-zinc-500">
                        <flux:icon :name="$stat['icon']" variant="micro" />
                        {{ $stat['label'] }}
                    </div>
                    <div class="mt-1 text-3xl font-bold tabular-nums {{ $stat['class'] }}">{{ $stat['value'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- Where every open test is --}}
        <div class="rounded-2xl bg-zinc-900/80 p-5 ring-1 ring-zinc-800">
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold text-white">{{ __('Where every open test is') }}</h2>
                <span class="text-xs text-zinc-500">{{ __('Tap a stage to see the patients · in-house last :in days, outsourced last :out days', ['in' => CeoLabOverview::IN_HOUSE_OPEN_DAYS, 'out' => CeoLabOverview::OUTSOURCED_OPEN_DAYS]) }}</span>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-6">
                @foreach ($stages as $stage)
                    @php $style = $tone[$stage['color']]; @endphp
                    <button type="button" wire:click="showStage('{{ $stage['key'] }}')"
                        class="group relative cursor-pointer overflow-hidden rounded-xl bg-gradient-to-b {{ $style['glow'] }} to-transparent p-4 text-left ring-1 {{ $style['ring'] }} transition hover:-translate-y-0.5 hover:ring-2">
                        <div class="flex items-center gap-2 text-sm font-medium text-zinc-200">
                            <span class="size-2 rounded-full {{ $style['dot'] }}"></span>
                            {{ $stage['label'] }}
                        </div>
                        <div class="mt-3 text-4xl font-bold tabular-nums {{ $stage['count'] > 0 ? 'text-white' : 'text-zinc-600' }}">{{ $stage['count'] }}</div>
                        <div class="mt-2 flex items-center justify-between text-xs">
                            <span class="rounded-md bg-zinc-800 px-1.5 py-0.5 text-zinc-300">{{ $stage['owner'] }}</span>
                            @if ($stage['oldest_minutes'] !== null)
                                <span class="{{ $style['text'] }}">{{ __('oldest :time', ['time' => $this->duration($stage['oldest_minutes'])]) }}</span>
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-5">
            {{-- Late --}}
            <div class="rounded-2xl bg-zinc-900/80 p-5 ring-1 ring-zinc-800 xl:col-span-3">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-lg font-semibold text-white">
                        <flux:icon.exclamation-triangle class="size-5 {{ $late->isNotEmpty() ? 'text-red-400' : 'text-emerald-400' }}" />
                        {{ __('Late / at risk') }}
                    </h2>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-bold {{ $late->isNotEmpty() ? 'bg-red-500/20 text-red-300' : 'bg-emerald-500/20 text-emerald-300' }}">{{ $late->count() }}</span>
                </div>
                <div class="flex max-h-80 flex-col divide-y divide-zinc-800 overflow-y-auto pe-1">
                    @forelse ($late->take(12) as $row)
                        @php $item = $row['item']; @endphp
                        <a wire:key="late-{{ $item->id }}" href="{{ route('lab.cases.show', $item->lab_invoice_id) }}" wire:navigate class="flex items-center gap-3 py-2.5 transition hover:bg-zinc-800/40">
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium uppercase text-zinc-100">{{ $item->labInvoice->patient?->name ?? __('Unknown') }} <span class="font-mono text-xs normal-case text-zinc-500">{{ $item->labInvoice->invoice_number }}</span></div>
                                <div class="truncate text-xs text-zinc-400">{{ trim($item->test_name) }} · {{ collect($stages)->firstWhere('key', $item->stage)['label'] }} · {{ $row['reason'] }}</div>
                            </div>
                            <span class="shrink-0 rounded-lg bg-red-500/15 px-2 py-1 text-xs font-semibold tabular-nums text-red-300">{{ __('+:time', ['time' => $this->duration($row['late_minutes'])]) }}</span>
                        </a>
                    @empty
                        <div class="flex flex-col items-center gap-2 py-10 text-center">
                            <flux:icon.check-badge class="size-8 text-emerald-400" />
                            <div class="text-sm text-zinc-300">{{ __('Nothing is late. Every open test is within its promised time.') }}</div>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Money --}}
            <div class="rounded-2xl bg-gradient-to-br from-emerald-500/10 via-zinc-900 to-zinc-900 p-5 ring-1 ring-emerald-500/20 xl:col-span-2">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-white">{{ __('Money') }}</h2>
                    <span class="text-xs text-zinc-500">{{ $periodLabel }}</span>
                </div>
                <div class="mt-3 text-4xl font-bold tabular-nums text-emerald-400">{{ $this->money($money['revenue']) }}</div>
                <div class="text-sm text-zinc-400">{{ trans_choice(':count case|:count cases', $money['cases'], ['count' => $money['cases']]) }} · {{ __('avg :amount', ['amount' => $this->money($money['average'])]) }}</div>

                <svg viewBox="0 0 300 66" class="mt-3 h-14 w-full" preserveAspectRatio="none">
                    <defs><linearGradient id="rev" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="rgb(52 211 153)" stop-opacity="0.35" /><stop offset="100%" stop-color="rgb(52 211 153)" stop-opacity="0" /></linearGradient></defs>
                    <polygon points="0,66 {{ $points }} 300,66" fill="url(#rev)" />
                    <polyline points="{{ $points }}" fill="none" stroke="rgb(52 211 153)" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                </svg>
                <div class="text-[10px] uppercase tracking-wide text-zinc-600">{{ __('Revenue, last 30 days') }}</div>

                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-zinc-400">{{ __('Discounts') }}</dt><dd class="text-right tabular-nums text-amber-300">−{{ $this->money($money['discounts']) }}</dd>
                    <dt class="text-zinc-400">{{ __('Doctor shares') }}</dt><dd class="text-right tabular-nums text-amber-300">−{{ $this->money($money['doctor_shares']) }}</dd>
                    <dt class="text-zinc-400">{{ __('Returns') }} ({{ $money['returns_count'] }})</dt><dd class="text-right tabular-nums text-rose-300">{{ $this->money($money['returns_amount']) }}</dd>
                    <dt class="font-medium text-zinc-200">{{ __('After doctor shares') }}</dt><dd class="text-right font-bold tabular-nums text-white">{{ $this->money($money['net']) }}</dd>
                </dl>

                <div class="mt-4">
                    <div class="flex h-2.5 overflow-hidden rounded-full bg-zinc-800">
                        <div class="bg-teal-400" style="width: {{ $money['in_house'] / $mixTotal * 100 }}%"></div>
                        <div class="bg-purple-400" style="width: {{ $money['outsourced'] / $mixTotal * 100 }}%"></div>
                    </div>
                    <div class="mt-1.5 flex justify-between text-xs">
                        <span class="text-teal-300">{{ __('In-house :amount', ['amount' => $this->money($money['in_house'])]) }}</span>
                        <span class="text-purple-300">{{ __('Outsourced :amount', ['amount' => $this->money($money['outsourced'])]) }}</span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                        @foreach ($money['by_mode'] as $mode => $amount)
                            <span class="rounded-md bg-zinc-800 px-2 py-1 text-zinc-300">{{ $mode }}: <span class="tabular-nums text-white">{{ $this->money($amount) }}</span></span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            {{-- Speed & quality --}}
            <div class="rounded-2xl bg-zinc-900/80 p-5 ring-1 ring-zinc-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-white">{{ __('Speed & quality') }}</h2>
                    <span class="text-xs text-zinc-500">{{ $periodLabel }}</span>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-zinc-800/60 p-3">
                        <div class="text-xs text-zinc-400">{{ __('Median turnaround') }}</div>
                        <div class="mt-1 text-2xl font-bold tabular-nums text-cyan-300">{{ $this->duration($speed['median_minutes']) }}</div>
                        <div class="text-[11px] text-zinc-500">{{ __('7-day: :time', ['time' => $this->duration($speed['week_median_minutes'])]) }}</div>
                    </div>
                    <div class="rounded-xl bg-zinc-800/60 p-3">
                        <div class="text-xs text-zinc-400">{{ __('On time') }}</div>
                        <div class="mt-1 text-2xl font-bold tabular-nums {{ ($speed['on_time_rate'] ?? 100) >= 90 ? 'text-emerald-400' : 'text-amber-300' }}">{{ $speed['on_time_rate'] !== null ? $speed['on_time_rate'].'%' : '—' }}</div>
                        <div class="text-[11px] text-zinc-500">{{ trans_choice(':count test completed|:count tests completed', $speed['completed'], ['count' => $speed['completed']]) }}</div>
                    </div>
                </div>
                <div class="mt-4 rounded-xl bg-zinc-800/60 p-3">
                    <div class="flex items-baseline justify-between">
                        <span class="text-xs text-zinc-400">{{ __('Retake rate') }}</span>
                        <span class="text-lg font-bold tabular-nums {{ ($speed['retake_rate'] ?? 0) > 3 ? 'text-rose-300' : 'text-zinc-100' }}">{{ $speed['retake_rate'] !== null ? $speed['retake_rate'].'%' : '—' }} <span class="text-xs font-normal text-zinc-500">({{ $speed['retakes'] }})</span></span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @forelse ($speed['retake_reasons'] as $reason => $count)
                            <span class="rounded-md bg-rose-500/10 px-2 py-0.5 text-xs text-rose-200 ring-1 ring-rose-500/20">{{ $reason }} · {{ $count }}</span>
                        @empty
                            <span class="text-xs text-zinc-500">{{ __('No retakes in this period.') }}</span>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- People --}}
            <div class="rounded-2xl bg-zinc-900/80 p-5 ring-1 ring-zinc-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-white">{{ __('People') }}</h2>
                    <span class="text-xs text-zinc-500">{{ $periodLabel }}</span>
                </div>
                <div class="mt-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Lab') }}</div>
                <div class="mt-1 flex flex-col gap-1.5 text-sm">
                    @forelse ($people['lab'] as $person)
                        <div class="flex items-center justify-between">
                            <span class="truncate text-zinc-200">{{ $person['name'] }}</span>
                            <span class="shrink-0 text-xs text-zinc-400"><span class="font-bold tabular-nums text-emerald-300">{{ $person['completed'] }}</span> {{ __('results') }} · <span class="tabular-nums text-sky-300">{{ $person['received'] }}</span> {{ __('samples') }}</span>
                        </div>
                    @empty
                        <span class="text-xs text-zinc-500">{{ __('No lab activity yet.') }}</span>
                    @endforelse
                </div>
                <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Reception') }}</div>
                <div class="mt-1 flex flex-col gap-1.5 text-sm">
                    @forelse ($people['reception'] as $person)
                        <div class="flex items-center justify-between">
                            <span class="truncate text-zinc-200">{{ $person['name'] }}</span>
                            <span class="shrink-0 text-xs text-zinc-400"><span class="font-bold tabular-nums text-purple-300">{{ $person['handed'] }}</span> {{ __('to rider') }} · <span class="tabular-nums text-rose-300">{{ $person['retakes'] }}</span> {{ __('retakes') }}</span>
                        </div>
                    @empty
                        <span class="text-xs text-zinc-500">{{ __('No rider handovers or retakes yet.') }}</span>
                    @endforelse
                </div>
            </div>

            {{-- Top referring doctors --}}
            <div class="rounded-2xl bg-zinc-900/80 p-5 ring-1 ring-zinc-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-white">{{ __('Top referring doctors') }}</h2>
                    <span class="text-xs text-zinc-500">{{ $periodLabel }}</span>
                </div>
                <div class="mt-3 flex flex-col gap-3">
                    @php $doctorMax = max(1, $money['top_doctors']->max('revenue') ?? 1); @endphp
                    @forelse ($money['top_doctors'] as $doctor)
                        <div>
                            <div class="flex items-baseline justify-between text-sm">
                                <span class="truncate text-zinc-200">{{ $doctor['name'] }}</span>
                                <span class="shrink-0 tabular-nums text-zinc-300">{{ $this->money($doctor['revenue']) }}</span>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-800"><div class="h-full rounded-full bg-gradient-to-r from-cyan-500 to-emerald-400" style="width: {{ $doctor['revenue'] / $doctorMax * 100 }}%"></div></div>
                            <div class="mt-0.5 text-[11px] text-zinc-500">{{ trans_choice(':count case|:count cases', $doctor['cases'], ['count' => $doctor['cases']]) }} · {{ __('share :amount', ['amount' => $this->money($doctor['share'])]) }}</div>
                        </div>
                    @empty
                        <span class="text-sm text-zinc-500">{{ __('No referred cases in this period.') }}</span>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            {{-- 30 day volume --}}
            <div class="rounded-2xl bg-zinc-900/80 p-5 ring-1 ring-zinc-800 xl:col-span-2">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-white">{{ __('Tests per day') }}</h2>
                    <span class="text-xs text-zinc-500">{{ __('Last 30 days') }}</span>
                </div>
                <div class="flex h-36 items-end gap-[3px]">
                    @foreach ($trends['days'] as $day)
                        <div class="group relative flex h-full flex-1 items-end" title="{{ $day['date']->format('D d M') }}: {{ $day['tests'] }} {{ __('tests') }}, {{ $this->money($day['revenue']) }}">
                            <div class="w-full rounded-t-sm transition-all duration-700 {{ $day['date']->isToday() ? 'bg-cyan-300' : ($day['date']->isWeekend() ? 'bg-cyan-700' : 'bg-cyan-500') }} group-hover:bg-white" style="height: {{ max(2, $day['tests'] / $testsMax * 100) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-[10px] text-zinc-500">
                    <span>{{ $trends['days'][0]['date']->format('d M') }}</span>
                    <span>{{ $trends['days'][14]['date']->format('d M') }}</span>
                    <span>{{ __('Today') }}</span>
                </div>
            </div>

            {{-- Top tests --}}
            <div class="rounded-2xl bg-zinc-900/80 p-5 ring-1 ring-zinc-800">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-white">{{ __('Top tests') }}</h2>
                    <span class="text-xs text-zinc-500">{{ __('this week vs last') }}</span>
                </div>
                <div class="flex flex-col gap-2">
                    @forelse ($trends['top_tests'] as $test)
                        @php $change = $test['this_week'] - $test['last_week']; @endphp
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="truncate text-zinc-200">{{ $test['name'] }}</span>
                            <span class="flex shrink-0 items-center gap-2 tabular-nums">
                                <span class="font-bold text-white">{{ $test['this_week'] }}</span>
                                <span class="w-12 rounded-md px-1.5 py-0.5 text-center text-[11px] {{ $change > 0 ? 'bg-emerald-500/15 text-emerald-300' : ($change < 0 ? 'bg-rose-500/15 text-rose-300' : 'bg-zinc-800 text-zinc-400') }}">{{ $change > 0 ? '▲'.$change : ($change < 0 ? '▼'.abs($change) : '–') }}</span>
                            </span>
                        </div>
                    @empty
                        <span class="text-sm text-zinc-500">{{ __('No tests this week.') }}</span>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Busy hours --}}
        <div class="rounded-2xl bg-zinc-900/80 p-5 ring-1 ring-zinc-800">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-white">{{ __('Busiest hours') }}</h2>
                <span class="text-xs text-zinc-500">{{ __('Tests billed, last 30 days') }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-separate border-spacing-1 text-[10px]">
                    <thead>
                        <tr>
                            <th></th>
                            @foreach ($busy['hours'] as $hour)
                                <th class="font-normal text-zinc-500">{{ \Carbon\CarbonImmutable::today()->setHour($hour)->format('ga') }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($busy['rows'] as $row)
                            <tr>
                                <td class="pe-2 text-right text-xs text-zinc-400">{{ $row['label'] }}</td>
                                @foreach ($row['hours'] as $hour => $count)
                                    <td class="h-6 min-w-6 rounded text-center tabular-nums {{ $count === 0 ? 'text-transparent' : ($count / $busy['max'] > 0.5 ? 'font-semibold text-zinc-950' : 'text-cyan-50') }}"
                                        style="background-color: rgb(34 211 238 / {{ $count > 0 ? 0.12 + $count / $busy['max'] * 0.88 : 0.04 }})"
                                        title="{{ $row['label'] }} {{ $hour }}:00 — {{ $count }}">{{ $count }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Stage drill-down --}}
    <flux:modal wire:model="showStageModal" class="w-full max-w-2xl">
        @if ($openStage && ($stage = collect($stages)->firstWhere('key', $openStage)))
            <flux:heading level="2">{{ $stage['label'] }}</flux:heading>
            <flux:text class="mt-1 text-sm">{{ __('Held by :owner · :count open', ['owner' => $stage['owner'], 'count' => $stage['count']]) }}</flux:text>
            <div class="mt-4 flex max-h-[60vh] flex-col divide-y divide-zinc-200 overflow-y-auto dark:divide-zinc-700">
                @forelse ($stage['items'] as $item)
                    <a wire:key="stage-item-{{ $item->id }}" href="{{ route('lab.cases.show', $item->lab_invoice_id) }}" wire:navigate class="flex items-center justify-between gap-3 py-2.5 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                        <div class="min-w-0">
                            <div class="truncate font-medium uppercase">{{ $item->labInvoice->patient?->name ?? __('Unknown') }} <span class="font-mono text-xs normal-case text-zinc-500">{{ $item->labInvoice->invoice_number }}</span></div>
                            <div class="truncate text-xs text-zinc-500">
                                {{ trim($item->test_name) }}
                                @if ($item->sample) · {{ $item->sample }} @endif
                                @if ($openStage === 'retake' && $item->latestRetake) · {{ $item->latestRetake->reason }} @endif
                            </div>
                        </div>
                        <span class="shrink-0 text-xs tabular-nums text-zinc-500">{{ $this->duration((int) ($openStage === 'partner_lab' ? ($item->given_at ?? $item->created_at) : $item->created_at)->diffInMinutes(now())) }}</span>
                    </a>
                @empty
                    <div class="py-8 text-center text-sm text-zinc-500">{{ __('Nothing here right now.') }}</div>
                @endforelse
            </div>
        @endif
    </flux:modal>
</div>
