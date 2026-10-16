<?php

use App\Models\AttendanceDayReview;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Pairing Backlog')] class extends Component
{
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        if (! rescue(fn () => CarbonImmutable::createFromFormat('Y-m', $this->month), null, false)) {
            $this->month = today()->format('Y-m');
        }
    }

    #[Computed]
    public function start(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }

    public function previousMonth(): void
    {
        $this->month = $this->start->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->start->addMonth()->format('Y-m');
    }

    /**
     * One entry per day of the month with its pairing status.
     *
     * @return Collection<int, array{date: CarbonImmutable, status: string, punches: int, late_punches: int, reviewer: ?string}>
     */
    #[Computed]
    public function days(): Collection
    {
        $start = $this->start;
        $end = $start->endOfMonth();
        $today = CarbonImmutable::today();

        $reviews = AttendanceDayReview::query()
            ->with('reviewer:id,name')
            ->whereBetween('review_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceDayReview $review): string => $review->review_date->toDateString());

        $punches = AttendancePunch::query()
            ->whereNotNull('attendance_device_user_id')
            ->whereBetween('punched_at', [$start, $end])
            ->get(['punched_at', 'created_at'])
            ->groupBy(fn (AttendancePunch $punch): string => $punch->punched_at->toDateString());

        return collect(range(0, $end->day - 1))->map(function (int $offset) use ($start, $today, $reviews, $punches): array {
            $date = $start->addDays($offset);
            $key = $date->toDateString();
            $review = $reviews->get($key);
            $dayPunches = $punches->get($key, collect());
            $latePunches = $review ? $dayPunches->filter(fn (AttendancePunch $punch): bool => $punch->created_at->gt($review->reviewed_at))->count() : 0;

            $status = match (true) {
                $date->gt($today) => 'future',
                $date->equalTo($today) => 'today',
                $review !== null && $latePunches > 0 => 'changed',
                $review !== null => 'reviewed',
                $dayPunches->isNotEmpty() => 'pending',
                default => 'quiet',
            };

            return [
                'date' => $date,
                'status' => $status,
                'punches' => $dayPunches->count(),
                'late_punches' => $latePunches,
                'reviewer' => $review?->reviewer?->name,
            ];
        });
    }

    #[Computed]
    public function unlinkedMachineUsers(): int
    {
        return AttendanceDeviceUser::query()->whereNull('employee_id')->count();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-hr-nav current="hr.backlog" />

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Pairing backlog') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Every day must be reviewed before its month can go to payroll.') }}</flux:text>
        </div>

        <div class="flex items-center gap-2">
            <flux:button icon="chevron-left" wire:click="previousMonth" :aria-label="__('Previous month')" />
            <flux:heading class="w-36 text-center">{{ $this->start->format('F Y') }}</flux:heading>
            <flux:button icon="chevron-right" wire:click="nextMonth" :aria-label="__('Next month')" />
        </div>
    </div>

    @php
        $counts = $this->days->countBy('status');
    @endphp

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <flux:card>
            <flux:text size="sm">{{ __('Days reviewed') }}</flux:text>
            <flux:heading size="xl">{{ ($counts['reviewed'] ?? 0) + ($counts['changed'] ?? 0) }} / {{ $this->days->whereNotIn('status', ['future', 'today'])->count() }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('Pending days') }}</flux:text>
            <flux:heading size="xl" class="text-amber-700 dark:text-amber-400">{{ $counts['pending'] ?? 0 }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('New punches after review') }}</flux:text>
            <flux:heading size="xl" class="text-red-700 dark:text-red-400">{{ $counts['changed'] ?? 0 }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('Unlinked machine users') }}</flux:text>
            <flux:heading size="xl">{{ $this->unlinkedMachineUsers }}</flux:heading>
            @if ($this->unlinkedMachineUsers > 0 && auth()->user()->isAdmin())
                <flux:link size="sm" :href="route('admin.attendance', ['view' => 'users'])" wire:navigate>{{ __('Link them') }}</flux:link>
            @endif
        </flux:card>
    </div>

    <flux:card>
        <div class="grid grid-cols-7 gap-2">
            @foreach ([__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')] as $dayName)
                <div wire:key="dow-{{ $loop->index }}" class="text-center text-xs font-semibold text-zinc-500">{{ $dayName }}</div>
            @endforeach

            @for ($blank = 0; $blank < $this->start->dayOfWeek; $blank++)
                <div wire:key="blank-{{ $blank }}"></div>
            @endfor

            @foreach ($this->days as $day)
                @php
                    [$label, $color, $classes] = match ($day['status']) {
                        'reviewed' => [__('Reviewed'), 'green', 'border-zinc-200 dark:border-zinc-700'],
                        'changed' => [__('New punches'), 'red', 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/30'],
                        'pending' => [__('Pending'), 'amber', 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30'],
                        'today' => [__('Today'), 'sky', 'border-2 border-sky-600'],
                        'quiet' => [__('No punches'), 'zinc', 'border-zinc-200 dark:border-zinc-700'],
                        default => ['', 'zinc', 'border-zinc-100 opacity-50 dark:border-zinc-800'],
                    };
                @endphp
                <a
                    wire:key="day-{{ $day['date']->toDateString() }}"
                    href="{{ route('hr.pairing', ['date' => $day['date']->toDateString()]) }}"
                    wire:navigate
                    class="flex min-h-24 flex-col gap-1 rounded-lg border p-2 hover:shadow-sm {{ $classes }}"
                >
                    <span class="text-base font-semibold">{{ $day['date']->day }}</span>
                    @if ($label !== '')
                        <flux:badge size="sm" :color="$color" class="self-start">{{ $label }}</flux:badge>
                    @endif
                    <span class="text-xs text-zinc-500">
                        @if ($day['status'] === 'changed')
                            {{ trans_choice('{1} 1 late punch|[2,*] :count late punches', $day['late_punches']) }}
                        @elseif ($day['reviewer'])
                            {{ __('by :name', ['name' => $day['reviewer']]) }}
                        @elseif ($day['punches'] > 0)
                            {{ trans_choice('{1} 1 punch|[2,*] :count punches', $day['punches']) }}
                        @endif
                    </span>
                </a>
            @endforeach
        </div>
    </flux:card>

    <flux:text size="sm">{{ __('Weeks start on Sunday to match the weekly roster. Click a day to open it on the pairing desk.') }}</flux:text>
</div>
