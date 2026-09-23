<?php

use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lab Dashboard')] class extends Component
{
    /**
     * How many days the trend, turnaround and "awaiting results" figures look back.
     */
    public const WINDOW_DAYS = 7;

    /**
     * Tests billed in the look-back window on cases that were not returned.
     *
     * @return Collection<int, LabInvoiceItem>
     */
    #[Computed]
    public function recentItems(): Collection
    {
        return LabInvoiceItem::query()
            ->where('created_at', '>=', today()->subDays(self::WINDOW_DAYS - 1))
            ->whereHas('labInvoice', fn ($invoice) => $invoice->where('status', '!=', 'returned'))
            ->with(['labInvoice.patient'])
            ->withCount('results')
            ->get();
    }

    /**
     * The headline numbers shown on the cards.
     *
     * @return array{to_receive: int, awaiting_results: int, completed_today: int, open_retakes: int, with_rider: int, billed_today: int}
     */
    #[Computed]
    public function headline(): array
    {
        $items = $this->recentItems;

        return [
            'to_receive' => LabInvoiceItem::query()->awaitingSample()->count(),
            'awaiting_results' => $this->awaitingResults()->count(),
            'completed_today' => $items->filter(fn (LabInvoiceItem $item) => $item->results_completed_at?->isToday())->count(),
            'open_retakes' => LabSampleRetake::query()->open()->count(),
            'with_rider' => $items->filter(fn (LabInvoiceItem $item) => ! $item->is_in_house && ! $item->isDone())->count(),
            'billed_today' => $items->filter(fn (LabInvoiceItem $item) => $item->created_at->isToday())->count(),
        ];
    }

    /**
     * Where today's in-house tests are in the lab: billed, sample in, results started, completed.
     *
     * @return list<array{label: string, count: int, color: string}>
     */
    #[Computed]
    public function pipeline(): array
    {
        $today = $this->recentItems->filter(fn (LabInvoiceItem $item) => $item->is_in_house && $item->created_at->isToday());

        return [
            ['label' => __('Billed'), 'count' => $today->count(), 'color' => 'bg-zinc-400'],
            ['label' => __('Sample in lab'), 'count' => $today->filter(fn ($item) => $item->sample_received_at || $item->results_completed_at)->count(), 'color' => 'bg-sky-500'],
            ['label' => __('Results started'), 'count' => $today->filter(fn ($item) => $item->results_count > 0 || $item->results_completed_at)->count(), 'color' => 'bg-violet-500'],
            ['label' => __('Completed'), 'count' => $today->filter(fn ($item) => $item->results_completed_at)->count(), 'color' => 'bg-emerald-500'],
        ];
    }

    /**
     * Tests billed and completed per hour today, over the working hours that have activity.
     *
     * @return list<array{hour: int, label: string, billed: int, completed: int}>
     */
    #[Computed]
    public function hourly(): array
    {
        $billed = $this->recentItems
            ->filter(fn (LabInvoiceItem $item) => $item->created_at->isToday())
            ->countBy(fn (LabInvoiceItem $item) => $item->created_at->hour);
        $completed = $this->recentItems
            ->filter(fn (LabInvoiceItem $item) => $item->results_completed_at?->isToday())
            ->countBy(fn (LabInvoiceItem $item) => $item->results_completed_at->hour);

        $hours = $billed->keys()->merge($completed->keys());
        $from = min(8, $hours->min() ?? 8);
        $to = max(min(23, max(now()->hour, 20)), $hours->max() ?? 0);

        return collect(range($from, $to))->map(fn (int $hour) => [
            'hour' => $hour,
            'label' => CarbonImmutable::today()->setHour($hour)->format('ga'),
            'billed' => (int) ($billed[$hour] ?? 0),
            'completed' => (int) ($completed[$hour] ?? 0),
        ])->all();
    }

    /**
     * Tests billed and in-house tests completed on each of the last few days.
     *
     * @return list<array{label: string, is_today: bool, billed: int, completed: int}>
     */
    #[Computed]
    public function daily(): array
    {
        return collect(range(self::WINDOW_DAYS - 1, 0))->map(function (int $daysAgo) {
            $day = today()->subDays($daysAgo);

            return [
                'label' => $daysAgo === 0 ? __('Today') : $day->format('D'),
                'is_today' => $daysAgo === 0,
                'billed' => $this->recentItems->filter(fn (LabInvoiceItem $item) => $item->created_at->isSameDay($day))->count(),
                'completed' => $this->recentItems->filter(fn (LabInvoiceItem $item) => $item->is_in_house && $item->results_completed_at?->isSameDay($day))->count(),
            ];
        })->all();
    }

    /**
     * Median minutes from billing to completed results for in-house tests entered in the HMS.
     *
     * @return array{today: ?int, week: ?int}
     */
    #[Computed]
    public function turnaround(): array
    {
        $completed = $this->recentItems->filter(fn (LabInvoiceItem $item) => $item->is_in_house
            && $item->results_completed_at
            && $item->results_imported_at === null);

        $median = function (Collection $items): ?int {
            $minutes = $items->map(fn (LabInvoiceItem $item) => (int) $item->created_at->diffInMinutes($item->results_completed_at))->sort()->values();

            return $minutes->isEmpty() ? null : (int) $minutes->median();
        };

        return [
            'today' => $median($completed->filter(fn (LabInvoiceItem $item) => $item->results_completed_at->isToday())),
            'week' => $median($completed),
        ];
    }

    /**
     * In-house tests whose sample is in the lab but results are not complete, oldest first.
     *
     * @return Collection<int, LabInvoiceItem>
     */
    public function awaitingResults(): Collection
    {
        return $this->recentItems
            ->filter(fn (LabInvoiceItem $item) => $item->is_in_house && $item->sample_received_at && ! $item->results_completed_at)
            ->sortBy('created_at')
            ->values();
    }

    /**
     * The most-ordered tests today.
     *
     * @return Collection<string, int>
     */
    #[Computed]
    public function topTests(): Collection
    {
        return $this->recentItems
            ->filter(fn (LabInvoiceItem $item) => $item->created_at->isToday())
            ->countBy(fn (LabInvoiceItem $item) => trim($item->test_name))
            ->sortDesc()
            ->take(6);
    }

    /**
     * In-house vs outsourced tests billed today.
     *
     * @return array{in_house: int, outsourced: int}
     */
    #[Computed]
    public function mix(): array
    {
        $today = $this->recentItems->filter(fn (LabInvoiceItem $item) => $item->created_at->isToday());

        return [
            'in_house' => $today->where('is_in_house', true)->count(),
            'outsourced' => $today->where('is_in_house', false)->count(),
        ];
    }

    /**
     * Tests completed today by each technician.
     *
     * @return Collection<int, array{name: string, count: int}>
     */
    #[Computed]
    public function byTechnician(): Collection
    {
        $counts = $this->recentItems
            ->filter(fn (LabInvoiceItem $item) => $item->results_completed_at?->isToday() && $item->results_completed_by)
            ->countBy('results_completed_by')
            ->sortDesc();

        $names = User::query()->whereIn('id', $counts->keys())->pluck('name', 'id');

        return $counts->map(fn (int $count, int $userId) => ['name' => $names[$userId] ?? __('Unknown'), 'count' => $count])->values();
    }

    /**
     * Sample types the lab is still waiting to receive.
     *
     * @return Collection<string, int>
     */
    #[Computed]
    public function samplesToReceive(): Collection
    {
        return LabInvoiceItem::query()
            ->awaitingSample()
            ->pluck('sample')
            ->map(fn (?string $sample) => filled($sample) ? trim($sample) : __('Not set'))
            ->countBy()
            ->sortDesc();
    }

    /**
     * Turn minutes into a short "1h 25m" label.
     */
    public function formatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return $minutes >= 60 ? sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60) : __(':count min', ['count' => $minutes]);
    }
}; ?>

<div wire:poll.60s>
    @php
        $user = auth()->user();
        $firstName = str($user->name)->before(' ')->toString() ?: $user->name;
        $greeting = match (true) {
            now()->hour < 12 => __('Good morning'),
            now()->hour < 17 => __('Good afternoon'),
            default => __('Good evening'),
        };
        $headline = $this->headline;
        $pipeline = $this->pipeline;
        $pipelineMax = max(1, $pipeline[0]['count']);
        $hourly = $this->hourly;
        $hourlyMax = max(1, collect($hourly)->max(fn ($hour) => max($hour['billed'], $hour['completed'])));
        $daily = $this->daily;
        $dailyMax = max(1, collect($daily)->max(fn ($day) => max($day['billed'], $day['completed'])));
        $turnaround = $this->turnaround;
        $awaiting = $this->awaitingResults();
        $mix = $this->mix;
        $mixTotal = $mix['in_house'] + $mix['outsourced'];
        $inHouseShare = $mixTotal > 0 ? $mix['in_house'] / $mixTotal : 0;
        $circumference = 2 * M_PI * 42;
        $topTests = $this->topTests;
        $topMax = max(1, $topTests->max() ?? 1);
        $completionRate = $pipeline[0]['count'] > 0 ? round($pipeline[3]['count'] / $pipeline[0]['count'] * 100) : null;
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-6">
        {{-- Hero --}}
        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-cyan-600 via-teal-600 to-emerald-600 p-6 text-white shadow-lg sm:p-8">
            <div class="pointer-events-none absolute -right-16 -top-16 size-64 rounded-full bg-white/10 blur-2xl"></div>
            <div class="pointer-events-none absolute -bottom-20 right-40 size-48 rounded-full bg-cyan-300/20 blur-2xl"></div>

            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm font-medium text-cyan-100">
                        <flux:icon.beaker variant="mini" />
                        {{ __('Mohsin Clinical Laboratory') }}
                    </div>
                    <h1 class="mt-2 text-2xl font-bold sm:text-3xl">{{ $greeting }}, {{ $firstName }}</h1>
                    <p class="mt-1 text-cyan-50/90">{{ now()->format('l, d F Y') }} · {{ __('Updated :time', ['time' => now()->format('g:i A')]) }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('lab.samples') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-teal-700 shadow-sm transition hover:bg-cyan-50">
                        <flux:icon.inbox-arrow-down variant="mini" />
                        {{ __('Receive samples') }}
                        @if ($headline['to_receive'] > 0)
                            <span class="rounded-full bg-amber-500 px-2 py-0.5 text-xs text-white">{{ $headline['to_receive'] }}</span>
                        @endif
                    </a>
                    <a href="{{ route('lab.cases', ['pendingOnly' => 1]) }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/30 backdrop-blur transition hover:bg-white/25">
                        <flux:icon.clipboard-document-check variant="mini" />
                        {{ __('Lab cases') }}
                    </a>
                </div>
            </div>

            <div class="relative mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
                    <div class="text-xs uppercase tracking-wide text-cyan-100">{{ __('Billed today') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ $headline['billed_today'] }}</div>
                </div>
                <div class="rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
                    <div class="text-xs uppercase tracking-wide text-cyan-100">{{ __('Completed today') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ $headline['completed_today'] }}</div>
                </div>
                <div class="rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
                    <div class="text-xs uppercase tracking-wide text-cyan-100">{{ __('Turnaround today') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ $this->formatMinutes($turnaround['today']) }}</div>
                </div>
                <div class="rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
                    <div class="text-xs uppercase tracking-wide text-cyan-100">{{ __('Done rate today') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ $completionRate !== null ? $completionRate.'%' : '—' }}</div>
                </div>
            </div>
        </div>

        {{-- Action cards --}}
        @php
            $cards = [
                ['label' => __('Samples to receive'), 'value' => $headline['to_receive'], 'hint' => __('from reception'), 'icon' => 'inbox-arrow-down', 'href' => route('lab.samples'), 'accent' => 'amber'],
                ['label' => __('Awaiting results'), 'value' => $headline['awaiting_results'], 'hint' => __('sample in lab'), 'icon' => 'pencil-square', 'href' => route('lab.cases', ['pendingOnly' => 1]), 'accent' => 'violet'],
                ['label' => __('Retakes open'), 'value' => $headline['open_retakes'], 'hint' => __('waiting on patient'), 'icon' => 'arrow-path', 'href' => route('lab.samples', ['tab' => 'retakes']), 'accent' => 'rose'],
                ['label' => __('Outsourced pending'), 'value' => $headline['with_rider'], 'hint' => __('partner lab, last 7 days'), 'icon' => 'truck', 'href' => route('lab.cases', ['pendingOnly' => 1]), 'accent' => 'sky'],
            ];
            $accents = [
                'amber' => ['ring' => 'hover:ring-amber-300 dark:hover:ring-amber-700', 'icon' => 'bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400', 'bar' => 'bg-amber-500'],
                'violet' => ['ring' => 'hover:ring-violet-300 dark:hover:ring-violet-700', 'icon' => 'bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-400', 'bar' => 'bg-violet-500'],
                'rose' => ['ring' => 'hover:ring-rose-300 dark:hover:ring-rose-700', 'icon' => 'bg-rose-100 text-rose-600 dark:bg-rose-900/40 dark:text-rose-400', 'bar' => 'bg-rose-500'],
                'sky' => ['ring' => 'hover:ring-sky-300 dark:hover:ring-sky-700', 'icon' => 'bg-sky-100 text-sky-600 dark:bg-sky-900/40 dark:text-sky-400', 'bar' => 'bg-sky-500'],
            ];
        @endphp
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($cards as $card)
                @php $accent = $accents[$card['accent']]; @endphp
                <a href="{{ $card['href'] }}" wire:navigate class="group relative overflow-hidden rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200 transition hover:-translate-y-0.5 hover:shadow-md dark:bg-zinc-900 dark:ring-zinc-700 {{ $accent['ring'] }}">
                    <div class="absolute inset-x-0 top-0 h-1 {{ $accent['bar'] }} opacity-80"></div>
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ $card['label'] }}</div>
                            <div class="mt-2 text-4xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $card['value'] }}</div>
                            <div class="mt-1 text-xs text-zinc-500">{{ $card['hint'] }}</div>
                        </div>
                        <div class="rounded-xl p-2.5 {{ $accent['icon'] }}">
                            <flux:icon :name="$card['icon']" class="size-6" />
                        </div>
                    </div>
                    <div class="mt-3 flex items-center gap-1 text-xs font-medium text-zinc-400 transition group-hover:text-zinc-700 dark:group-hover:text-zinc-200">
                        {{ __('Open') }} <flux:icon.arrow-right variant="micro" />
                    </div>
                </a>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            {{-- Today's flow --}}
            <flux:card class="xl:col-span-2">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading level="2">{{ __("Today's in-house flow") }}</flux:heading>
                        <flux:text class="text-sm">{{ __('From billing to completed results.') }}</flux:text>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                    @foreach ($pipeline as $index => $stage)
                        <div class="relative">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ $stage['label'] }}</span>
                                <span class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $stage['count'] }}</span>
                            </div>
                            <div class="mt-2 h-3 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full rounded-full {{ $stage['color'] }} transition-all duration-700" style="width: {{ round($stage['count'] / $pipelineMax * 100) }}%"></div>
                            </div>
                            @if ($index > 0 && $pipeline[0]['count'] > 0)
                                <div class="mt-1 text-xs text-zinc-500">{{ round($stage['count'] / $pipeline[0]['count'] * 100) }}%</div>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Hourly chart --}}
                <div class="mt-8">
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('By hour today') }}</span>
                        <div class="flex items-center gap-3 text-xs text-zinc-500">
                            <span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-cyan-500"></span>{{ __('Billed') }}</span>
                            <span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-emerald-500"></span>{{ __('Completed') }}</span>
                        </div>
                    </div>
                    <div class="flex h-40 items-end gap-1 border-b border-zinc-200 dark:border-zinc-700">
                        @foreach ($hourly as $hour)
                            <div class="group relative flex h-full flex-1 items-end justify-center gap-0.5" title="{{ $hour['label'] }}: {{ $hour['billed'] }} {{ __('billed') }}, {{ $hour['completed'] }} {{ __('completed') }}">
                                <div class="w-full max-w-3 rounded-t bg-cyan-500/90 transition-all duration-700 group-hover:bg-cyan-400" style="height: {{ $hour['billed'] / $hourlyMax * 100 }}%"></div>
                                <div class="w-full max-w-3 rounded-t bg-emerald-500/90 transition-all duration-700 group-hover:bg-emerald-400" style="height: {{ $hour['completed'] / $hourlyMax * 100 }}%"></div>
                                @if ($hour['hour'] === now()->hour)
                                    <div class="absolute -top-1 size-1.5 rounded-full bg-amber-500"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1 flex gap-1">
                        @foreach ($hourly as $hour)
                            <div class="flex-1 text-center text-[10px] tabular-nums {{ $hour['hour'] === now()->hour ? 'font-bold text-amber-600' : 'text-zinc-400' }}">{{ $loop->index % 2 === 0 ? $hour['label'] : '' }}</div>
                        @endforeach
                    </div>
                </div>
            </flux:card>

            {{-- Mix donut + turnaround --}}
            <flux:card class="flex flex-col">
                <flux:heading level="2">{{ __("Today's mix") }}</flux:heading>
                <flux:text class="text-sm">{{ __('In-house vs outsourced tests.') }}</flux:text>

                <div class="mt-4 flex items-center justify-center">
                    <div class="relative">
                        <svg viewBox="0 0 100 100" class="size-44 -rotate-90">
                            <circle cx="50" cy="50" r="42" fill="none" stroke-width="12" class="stroke-violet-200 dark:stroke-violet-900/60" />
                            @if ($mixTotal > 0)
                                <circle cx="50" cy="50" r="42" fill="none" stroke-width="12" stroke-linecap="round" class="stroke-teal-500 transition-all duration-700"
                                    stroke-dasharray="{{ $circumference * $inHouseShare }} {{ $circumference }}" />
                            @endif
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $mixTotal }}</span>
                            <span class="text-xs text-zinc-500">{{ __('tests') }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-xl bg-teal-50 p-3 dark:bg-teal-900/20">
                        <div class="flex items-center gap-1.5 text-teal-700 dark:text-teal-300"><span class="size-2.5 rounded-full bg-teal-500"></span>{{ __('In-house') }}</div>
                        <div class="mt-1 text-xl font-bold tabular-nums">{{ $mix['in_house'] }}</div>
                    </div>
                    <div class="rounded-xl bg-violet-50 p-3 dark:bg-violet-900/20">
                        <div class="flex items-center gap-1.5 text-violet-700 dark:text-violet-300"><span class="size-2.5 rounded-full bg-violet-300"></span>{{ __('Outsourced') }}</div>
                        <div class="mt-1 text-xl font-bold tabular-nums">{{ $mix['outsourced'] }}</div>
                    </div>
                </div>

                <div class="mt-auto pt-5">
                    <div class="flex items-center justify-between rounded-xl bg-zinc-50 p-3 text-sm dark:bg-zinc-800/60">
                        <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon.clock variant="mini" />
                            {{ __('Median turnaround, 7 days') }}
                        </div>
                        <span class="font-bold tabular-nums">{{ $this->formatMinutes($turnaround['week']) }}</span>
                    </div>
                </div>
            </flux:card>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            {{-- Waiting for results --}}
            <flux:card class="xl:col-span-2">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Waiting for results') }}</flux:heading>
                        <flux:text class="text-sm">{{ __('Sample is in the lab. Oldest first.') }}</flux:text>
                    </div>
                    <flux:badge color="violet">{{ $awaiting->count() }}</flux:badge>
                </div>

                <div class="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($awaiting->take(8) as $item)
                        @php
                            $minutes = (int) $item->created_at->diffInMinutes(now());
                            $ageClass = match (true) {
                                $minutes >= 240 => 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
                                $minutes >= 120 => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                                default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                            };
                        @endphp
                        <a wire:key="waiting-{{ $item->id }}" href="{{ route('lab.cases.show', $item->lab_invoice_id) }}" wire:navigate class="-mx-2 flex items-center gap-3 rounded-lg px-2 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                            <flux:avatar size="sm" :name="$item->labInvoice->patient?->name ?? '?'" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium uppercase text-zinc-900 dark:text-zinc-100">{{ $item->labInvoice->patient?->name ?? __('Unknown') }}</div>
                                <div class="truncate text-xs text-zinc-500">
                                    {{ trim($item->test_name) }} · <span class="font-mono">{{ $item->labInvoice->invoice_number }}</span>
                                    @if ($item->results_count > 0)
                                        · <span class="text-violet-600 dark:text-violet-400">{{ __('in progress') }}</span>
                                    @endif
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold tabular-nums {{ $ageClass }}">{{ $this->formatMinutes($minutes) }}</span>
                        </a>
                    @empty
                        <div class="flex flex-col items-center gap-2 py-10 text-center">
                            <div class="rounded-full bg-emerald-100 p-3 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400"><flux:icon.check-badge class="size-7" /></div>
                            <div class="text-sm font-medium">{{ __('All caught up') }}</div>
                            <div class="text-xs text-zinc-500">{{ __('No received sample is waiting for results.') }}</div>
                        </div>
                    @endforelse
                    @if ($awaiting->count() > 8)
                        <a href="{{ route('lab.cases', ['pendingOnly' => 1]) }}" wire:navigate class="pt-3 text-center text-sm font-medium text-teal-600 hover:underline">{{ __('See all :count', ['count' => $awaiting->count()]) }}</a>
                    @endif
                </div>
            </flux:card>

            <div class="flex flex-col gap-6">
                {{-- Samples to receive --}}
                <flux:card>
                    <flux:heading level="2">{{ __('Samples to collect') }}</flux:heading>
                    <flux:text class="mb-3 text-sm">{{ __('Not yet received from reception.') }}</flux:text>
                    <div class="flex flex-wrap gap-2">
                        @forelse ($this->samplesToReceive as $sample => $count)
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:text-amber-200 dark:ring-amber-800">
                                <flux:icon.beaker variant="micro" />
                                {{ $sample }}
                                <span class="rounded bg-amber-200/70 px-1.5 text-xs font-bold tabular-nums dark:bg-amber-800/60">{{ $count }}</span>
                            </span>
                        @empty
                            <span class="text-sm text-zinc-500">{{ __('Nothing to collect right now.') }}</span>
                        @endforelse
                    </div>
                </flux:card>

                {{-- Technicians --}}
                <flux:card>
                    <flux:heading level="2">{{ __('Completed today by') }}</flux:heading>
                    <div class="mt-3 flex flex-col gap-2">
                        @forelse ($this->byTechnician as $index => $technician)
                            <div class="flex items-center gap-3">
                                <span class="flex size-6 items-center justify-center rounded-full text-xs font-bold {{ $index === 0 ? 'bg-amber-400 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' }}">{{ $index + 1 }}</span>
                                <span class="flex-1 truncate text-sm">{{ $technician['name'] }}</span>
                                <span class="text-sm font-bold tabular-nums">{{ $technician['count'] }}</span>
                            </div>
                        @empty
                            <span class="text-sm text-zinc-500">{{ __('No results completed yet today.') }}</span>
                        @endforelse
                    </div>
                </flux:card>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            {{-- 7-day trend --}}
            <flux:card>
                <div class="mb-4 flex items-center justify-between">
                    <flux:heading level="2">{{ __('Last 7 days') }}</flux:heading>
                    <div class="flex items-center gap-3 text-xs text-zinc-500">
                        <span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-cyan-500"></span>{{ __('Billed') }}</span>
                        <span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-emerald-500"></span>{{ __('Completed in-house') }}</span>
                    </div>
                </div>
                <div class="flex h-44 items-end gap-3">
                    @foreach ($daily as $day)
                        <div class="flex h-full flex-1 flex-col items-center justify-end gap-1">
                            <div class="flex h-full w-full items-end justify-center gap-1">
                                <div class="relative w-1/3 rounded-t-md bg-cyan-500 transition-all duration-700" style="height: {{ $day['billed'] / $dailyMax * 100 }}%" title="{{ $day['billed'] }} {{ __('billed') }}">
                                    @if ($day['billed'] > 0)
                                        <span class="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-semibold tabular-nums text-zinc-500">{{ $day['billed'] }}</span>
                                    @endif
                                </div>
                                <div class="relative w-1/3 rounded-t-md bg-emerald-500 transition-all duration-700" style="height: {{ $day['completed'] / $dailyMax * 100 }}%" title="{{ $day['completed'] }} {{ __('completed') }}">
                                    @if ($day['completed'] > 0)
                                        <span class="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-semibold tabular-nums text-zinc-500">{{ $day['completed'] }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex gap-3 border-t border-zinc-200 pt-2 dark:border-zinc-700">
                    @foreach ($daily as $day)
                        <div class="flex-1 text-center text-xs {{ $day['is_today'] ? 'font-bold text-teal-600' : 'text-zinc-500' }}">{{ $day['label'] }}</div>
                    @endforeach
                </div>
            </flux:card>

            {{-- Top tests --}}
            <flux:card>
                <flux:heading level="2" class="mb-4">{{ __('Top tests today') }}</flux:heading>
                <div class="flex flex-col gap-3">
                    @forelse ($topTests as $test => $count)
                        <div>
                            <div class="mb-1 flex items-center justify-between text-sm">
                                <span class="truncate font-medium">{{ $test }}</span>
                                <span class="tabular-nums text-zinc-500">{{ $count }}</span>
                            </div>
                            <div class="h-2.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full rounded-full bg-gradient-to-r from-cyan-500 to-emerald-500 transition-all duration-700" style="width: {{ round($count / $topMax * 100) }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-zinc-500">{{ __('No tests billed yet today.') }}</div>
                    @endforelse
                </div>
            </flux:card>
        </div>
    </div>
</div>
