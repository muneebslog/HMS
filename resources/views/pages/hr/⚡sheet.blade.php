<?php

use App\Enums\StaffCategory;
use App\Services\AttendanceSheetService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Monthly Attendance Sheet')] class extends Component
{
    /** Cell colours per tone (light and dark). */
    public const Tones = [
        'shift' => 'bg-sky-100 text-sky-900 dark:bg-sky-900/50 dark:text-sky-100',
        'night' => 'bg-indigo-800 text-white',
        'custom' => 'bg-cyan-100 text-cyan-900 dark:bg-cyan-900/50 dark:text-cyan-100',
        'double' => 'bg-violet-700 text-white',
        'leave' => 'bg-green-100 text-green-900 dark:bg-green-900/50 dark:text-green-100',
        'unpaid' => 'bg-amber-100 text-amber-900 dark:bg-amber-900/50 dark:text-amber-100',
        'absent' => 'bg-red-100 text-red-900 ring-1 ring-red-400 dark:bg-red-900/50 dark:text-red-100',
        'holiday' => 'bg-zinc-200 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-100',
        'unreviewed' => 'border border-dashed border-amber-400 bg-white text-amber-700 dark:bg-zinc-900 dark:text-amber-400',
        'off' => 'bg-zinc-50 text-zinc-400 dark:bg-zinc-800',
        'future' => 'bg-zinc-50 dark:bg-zinc-800/50',
    ];

    #[Url]
    public string $month = '';

    #[Url]
    public string $category = '';

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

    /**
     * @return array{days: list<CarbonImmutable>, rows: \Illuminate\Support\Collection<int, array<string, mixed>>}
     */
    #[Computed]
    public function sheet(): array
    {
        return app(AttendanceSheetService::class)->month($this->start, StaffCategory::tryFrom($this->category));
    }

    public function previousMonth(): void
    {
        $this->month = $this->start->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->start->addMonth()->format('Y-m');
    }

    public function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="print:hidden">
        <x-hr-nav current="hr.sheet" />
    </div>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Monthly attendance sheet') }} — {{ $this->start->format('F Y') }}</flux:heading>
            <flux:text class="mt-1 print:hidden">{{ __('Built only from confirmed duties, leaves and holidays.') }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-2 print:hidden">
            <flux:button icon="chevron-left" wire:click="previousMonth" :aria-label="__('Previous month')" />
            <flux:button icon="chevron-right" wire:click="nextMonth" :aria-label="__('Next month')" />
            <flux:select wire:model.live="category" class="w-48">
                <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
                @foreach (StaffCategory::cases() as $staffCategory)
                    @if ($staffCategory !== StaffCategory::Doctor)
                        <flux:select.option value="{{ $staffCategory->value }}">{{ $staffCategory->label() }}</flux:select.option>
                    @endif
                @endforeach
            </flux:select>
            <flux:button icon="printer" x-on:click="window.print()">{{ __('Print') }}</flux:button>
        </div>
    </div>

    <flux:card class="overflow-x-auto p-2!">
        <table class="border-separate border-spacing-[3px] text-sm">
            <thead>
                <tr>
                    <th scope="col" class="sticky left-0 z-10 min-w-40 bg-white px-2 text-left text-xs dark:bg-zinc-900">{{ __('Staff') }}</th>
                    @foreach ($this->sheet['days'] as $day)
                        <th scope="col" wire:key="head-{{ $day->day }}" @class(['w-8 text-center text-[11px] font-semibold', 'text-red-700 dark:text-red-400' => $day->isSunday(), 'text-zinc-500' => ! $day->isSunday()])>
                            {{ $day->day }}<br>{{ mb_substr($day->format('D'), 0, 2) }}
                        </th>
                    @endforeach
                    <th scope="col" class="px-1 text-[11px]" title="{{ __('Duties worked / expected') }}">{{ __('Duties') }}</th>
                    <th scope="col" class="px-1 text-[11px]">{{ __('Paid L') }}</th>
                    <th scope="col" class="px-1 text-[11px]">{{ __('Unpaid') }}</th>
                    <th scope="col" class="px-1 text-[11px]">{{ __('Absent') }}</th>
                    <th scope="col" class="px-1 text-[11px]">{{ __('Late') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->sheet['rows'] as $row)
                    <tr wire:key="sheet-row-{{ $row['employee']->id }}">
                        <th scope="row" class="sticky left-0 z-10 whitespace-nowrap bg-white px-2 text-left font-medium dark:bg-zinc-900">
                            {{ $row['employee']->name }}
                            <div class="text-[11px] font-normal text-zinc-500">{{ $row['employee']->staff_category->label() }}</div>
                        </th>
                        @foreach ($row['cells'] as $index => $cell)
                            <td wire:key="cell-{{ $row['employee']->id }}-{{ $index }}">
                                <div class="relative flex size-8 items-center justify-center rounded text-[11px] font-bold {{ $this::Tones[$cell['tone']] }}" title="{{ $cell['title'] }}">
                                    {{ $cell['code'] }}
                                    @if ($cell['late'])
                                        <span class="absolute right-0.5 top-0.5 size-1.5 rounded-full bg-orange-600" aria-label="{{ __('Late') }}"></span>
                                    @endif
                                </div>
                            </td>
                        @endforeach
                        <td class="px-1 text-center font-bold">{{ $row['duties'] }}<span class="font-normal text-zinc-500">/{{ $row['expected'] }}</span></td>
                        <td class="px-1 text-center">{{ $this->number($row['paid_leave']) }}</td>
                        <td class="px-1 text-center text-amber-700 dark:text-amber-400">{{ $this->number($row['unpaid_leave']) }}</td>
                        <td class="px-1 text-center font-semibold text-red-700 dark:text-red-400">{{ $this->number($row['absent']) }}</td>
                        <td class="px-1 text-center">{{ $row['late'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="40" class="py-8 text-center text-zinc-500">{{ __('No staff who punch the machine.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>

    <flux:card class="flex flex-wrap gap-4 text-xs">
        @foreach ([
            ['M', 'shift', __('Morning / other shift (first letter)')],
            ['N', 'night', __('Night')],
            ['C', 'custom', __('Custom timing')],
            ['2', 'double', __('Double duty')],
            ['L', 'leave', __('Paid leave')],
            ['U', 'unpaid', __('Informed, deducted')],
            ['A', 'absent', __('Absent (uninformed)')],
            ['H', 'holiday', __('Public holiday')],
            ['?', 'unreviewed', __('Day not reviewed')],
            ['–', 'off', __('No duty')],
        ] as [$code, $tone, $label])
            <span wire:key="legend-{{ $tone }}" class="inline-flex items-center gap-1.5">
                <span class="flex size-6 items-center justify-center rounded text-[11px] font-bold {{ $this::Tones[$tone] }}">{{ $code }}</span>
                {{ $label }}
            </span>
        @endforeach
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-orange-600"></span>{{ __('Late past grace') }}</span>
    </flux:card>
</div>
