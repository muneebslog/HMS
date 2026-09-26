<?php

use App\Models\Shift;
use App\Services\MedicationDeliveryLogService;
use App\Services\MedicationDeliverySummaryService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Medication Deliveries')] class extends Component
{
    use WithPagination;

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $shiftId = '';

    public string $typeFilter = 'all';

    public string $keyword = '';

    public function mount(): void
    {
        $this->dateFrom = now()->subDays(6)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    public function updatedDateFrom(): void
    {
        $this->forgetShiftOutsideRange();
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->forgetShiftOutsideRange();
        $this->resetPage();
    }

    public function updatedShiftId(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedKeyword(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, object>
     */
    #[Computed]
    public function deliveries(): LengthAwarePaginator
    {
        $window = $this->window();

        if ($window === null) {
            return new LengthAwarePaginator([], 0, 20);
        }

        return app(MedicationDeliveryLogService::class)->paginate(
            $window['from'],
            $window['to'],
            $this->typeFilter,
            $this->keyword,
        );
    }

    /**
     * Shifts opened within the selected date range, newest first.
     *
     * @return Collection<int, Shift>
     */
    #[Computed]
    public function shifts(): Collection
    {
        $dateFrom = $this->parseDate($this->dateFrom);
        $dateTo = $this->parseDate($this->dateTo);

        if ($dateFrom === null || $dateTo === null || $dateFrom->isAfter($dateTo)) {
            return new Collection;
        }

        return Shift::query()
            ->with('user:id,name')
            ->whereBetween('opened_at', [$dateFrom->copy()->startOfDay(), $dateTo->copy()->endOfDay()])
            ->latest('opened_at')
            ->get();
    }

    #[Computed]
    public function selectedShift(): ?Shift
    {
        if ($this->shiftId === '') {
            return null;
        }

        return $this->shifts->firstWhere('id', (int) $this->shiftId);
    }

    /**
     * Slip counts and ordered vs given totals for the selected shift or date range.
     *
     * @return array{
     *     slips: list<array{service_id: int|null, name: string, is_drip: bool, made: int, returned: int}>,
     *     drips: array{slips: int, returned: int, without_order: int, orders: int, ordered: int, started: int, done: int, left: int},
     *     injections: array{ordered: int, given: int, left: int},
     *     medicines: array{ordered: int, given: int, left: int}
     * }|null
     */
    #[Computed]
    public function summary(): ?array
    {
        $summaries = app(MedicationDeliverySummaryService::class);

        if ($this->selectedShift !== null) {
            return $summaries->forShift($this->selectedShift);
        }

        $window = $this->window();

        return $window === null ? null : $summaries->forDateRange($window['from'], $window['to']);
    }

    #[Computed]
    public function hasInvalidRange(): bool
    {
        $dateFrom = $this->parseDate($this->dateFrom);
        $dateTo = $this->parseDate($this->dateTo);

        return $dateFrom === null
            || $dateTo === null
            || $dateFrom->isAfter($dateTo);
    }

    public function shiftLabel(Shift $shift): string
    {
        $closedAt = $shift->closed_at;

        $end = match (true) {
            $closedAt === null => __('open'),
            $closedAt->isSameDay($shift->opened_at) => $closedAt->format('H:i'),
            default => $closedAt->format('d M H:i'),
        };

        return $shift->opened_at->format('d M H:i').' → '.$end.' · '.($shift->user?->name ?? __('Unknown'));
    }

    /**
     * The exact time window the table and summary cover.
     *
     * @return array{from: CarbonInterface, to: CarbonInterface}|null
     */
    private function window(): ?array
    {
        if ($this->selectedShift !== null) {
            return [
                'from' => $this->selectedShift->opened_at,
                'to' => $this->selectedShift->closed_at ?? now(),
            ];
        }

        $dateFrom = $this->parseDate($this->dateFrom);
        $dateTo = $this->parseDate($this->dateTo);

        if ($dateFrom === null || $dateTo === null || $dateFrom->isAfter($dateTo)) {
            return null;
        }

        return ['from' => $dateFrom->copy()->startOfDay(), 'to' => $dateTo->copy()->endOfDay()];
    }

    private function forgetShiftOutsideRange(): void
    {
        unset($this->shifts, $this->selectedShift);

        if ($this->shiftId !== '' && $this->selectedShift === null) {
            $this->shiftId = '';
        }
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (Throwable) {
            return null;
        }

        return $date->toDateString() === $value ? $date : null;
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('Medication Deliveries') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500">
            {{ __('Reception slips, what was ordered, and what was given at ER and drip stations.') }}
        </flux:text>
    </div>

    <flux:card>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <flux:input
                type="date"
                wire:model.live.change="dateFrom"
                label="{{ __('Date From') }}"
                :max="$dateTo"
            />

            <flux:input
                type="date"
                wire:model.live.change="dateTo"
                label="{{ __('Date To') }}"
                :min="$dateFrom"
            />

            <flux:select wire:model.live="shiftId" label="{{ __('Shift') }}">
                <flux:select.option value="">{{ __('All shifts in range') }}</flux:select.option>
                @foreach ($this->shifts as $shift)
                    <flux:select.option value="{{ $shift->id }}" wire:key="shift-option-{{ $shift->id }}">
                        {{ $this->shiftLabel($shift) }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="typeFilter" label="{{ __('Type') }}">
                <flux:select.option value="all">{{ __('All types') }}</flux:select.option>
                <flux:select.option value="medicine">{{ __('Medicine') }}</flux:select.option>
                <flux:select.option value="injection">{{ __('Injection') }}</flux:select.option>
                <flux:select.option value="drip">{{ __('Drip') }}</flux:select.option>
            </flux:select>

            <flux:input
                wire:model.live.debounce.300ms="keyword"
                label="{{ __('Search') }}"
                placeholder="{{ __('Item, patient, MRN...') }}"
            />
        </div>

        @if ($this->hasInvalidRange)
            <flux:text class="mt-4 text-sm text-red-600 dark:text-red-400">
                {{ __('Enter a valid date range. The start date must not be after the end date.') }}
            </flux:text>
        @endif
    </flux:card>

    @if ($summary = $this->summary)
        <flux:card class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Summary') }}</flux:heading>
                <flux:text class="text-sm text-zinc-500">
                    @if ($this->selectedShift)
                        {{ __('Shift') }} {{ $this->shiftLabel($this->selectedShift) }}
                    @else
                        {{ __('All shifts from :from to :to', ['from' => $dateFrom, 'to' => $dateTo]) }}
                    @endif
                </flux:text>
            </div>

            <div>
                <flux:subheading class="mb-2 text-xs font-semibold uppercase tracking-wide">{{ __('Slips made at reception') }}</flux:subheading>

                @if ($summary['slips'] === [])
                    <flux:text class="text-sm text-zinc-500">{{ __('No slips in this period.') }}</flux:text>
                @else
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-6">
                        @foreach ($summary['slips'] as $slip)
                            <div
                                wire:key="slip-{{ $slip['service_id'] ?? 'none' }}"
                                @class([
                                    'rounded-lg border p-3',
                                    'border-sky-300 bg-sky-50 dark:border-sky-700 dark:bg-sky-950/40' => $slip['is_drip'],
                                    'border-zinc-200 dark:border-zinc-700' => ! $slip['is_drip'],
                                ])
                            >
                                <div class="truncate text-sm text-zinc-600 dark:text-zinc-300" title="{{ $slip['name'] }}">{{ $slip['name'] }}</div>
                                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $slip['made'] }}</div>
                                @if ($slip['returned'] > 0)
                                    <div class="text-xs text-red-600 dark:text-red-400">{{ trans_choice(':count returned|:count returned', $slip['returned']) }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 p-4 lg:col-span-3 dark:border-zinc-700">
                    <div class="mb-3 flex items-center gap-2">
                        <flux:badge size="sm" color="zinc">{{ __('Drip') }}</flux:badge>
                        <span class="text-sm font-medium">{{ __('Short stay / drips') }}</span>
                    </div>

                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Slips made') }}</dt>
                            <dd class="text-xl font-semibold tabular-nums">{{ $summary['drips']['slips'] }}</dd>
                            @if ($summary['drips']['returned'] > 0)
                                <dd class="text-xs text-red-600 dark:text-red-400">{{ trans_choice(':count returned|:count returned', $summary['drips']['returned']) }}</dd>
                            @endif
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Drips ordered') }}</dt>
                            <dd class="text-xl font-semibold tabular-nums">{{ $summary['drips']['ordered'] }}</dd>
                            <dd class="text-xs text-zinc-500">{{ trans_choice('on :count order|on :count orders', $summary['drips']['orders']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Started') }}</dt>
                            <dd class="text-xl font-semibold tabular-nums">{{ $summary['drips']['started'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Done') }}</dt>
                            <dd class="text-xl font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $summary['drips']['done'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Left') }}</dt>
                            <dd @class([
                                'text-xl font-semibold tabular-nums',
                                'text-amber-600 dark:text-amber-400' => $summary['drips']['left'] > 0,
                            ])>{{ $summary['drips']['left'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Slips without drip order') }}</dt>
                            <dd @class([
                                'text-xl font-semibold tabular-nums',
                                'text-red-600 dark:text-red-400' => $summary['drips']['without_order'] > 0,
                            ])>{{ $summary['drips']['without_order'] }}</dd>
                        </div>
                    </dl>

                    @if ($summary['drips']['without_order'] > 0)
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4">
                            <flux:callout.text>
                                {{ trans_choice(':count short stay slip has no drip order entered in the system.|:count short stay slips have no drip order entered in the system.', $summary['drips']['without_order']) }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif
                </div>

                @foreach ([
                    'injections' => ['label' => __('Injections'), 'color' => 'amber'],
                    'medicines' => ['label' => __('Medicines'), 'color' => 'sky'],
                ] as $key => $meta)
                    <div wire:key="summary-{{ $key }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="mb-3">
                            <flux:badge size="sm" :color="$meta['color']">{{ $meta['label'] }}</flux:badge>
                        </div>
                        <dl class="grid grid-cols-3 gap-3">
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('Ordered') }}</dt>
                                <dd class="text-xl font-semibold tabular-nums">{{ $summary[$key]['ordered'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('Given') }}</dt>
                                <dd class="text-xl font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $summary[$key]['given'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('Left') }}</dt>
                                <dd @class([
                                    'text-xl font-semibold tabular-nums',
                                    'text-amber-600 dark:text-amber-400' => $summary[$key]['left'] > 0,
                                ])>{{ $summary[$key]['left'] }}</dd>
                            </div>
                        </dl>
                    </div>
                @endforeach
            </div>

            <flux:text class="text-xs text-zinc-500">
                {{ __('Slips are counted by the shift they were billed in. Ordered, given and left count orders written in this period.') }}
            </flux:text>
        </flux:card>
    @endif

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Date & time') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Item') }}</flux:table.column>
                <flux:table.column>{{ __('Details') }}</flux:table.column>
                <flux:table.column>{{ __('Patient') }}</flux:table.column>
                <flux:table.column>{{ __('Token') }}</flux:table.column>
                <flux:table.column>{{ __('By') }}</flux:table.column>
                <flux:table.column>{{ __('Doctor') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->deliveries as $row)
                    <flux:table.row wire:key="delivery-{{ $row->type }}-{{ $row->line_id }}">
                        <flux:table.cell>
                            <div>{{ ($row->started_at ?? $row->occurred_at)->format('Y-m-d H:i') }}</div>
                            @if ($row->type === 'drip' && $row->done_at)
                                <div class="text-xs text-zinc-500">{{ __('Done') }} {{ $row->done_at->format('Y-m-d H:i') }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($row->type === 'medicine')
                                <flux:badge size="sm" color="sky">{{ __('Medicine') }}</flux:badge>
                            @elseif ($row->type === 'injection')
                                <flux:badge size="sm" color="amber">{{ __('Injection') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Drip') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $row->item_name }}</flux:table.cell>
                        <flux:table.cell>{{ $row->detail ?? '-' }}</flux:table.cell>
                        <flux:table.cell>
                            <div>{{ $row->patient_name }}</div>
                            @if (filled($row->mrn))
                                <div class="text-xs text-zinc-500">{{ $row->mrn }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $row->token_number ?? '-' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($row->type === 'drip')
                                <div>{{ $row->started_by ?? '-' }}</div>
                                @if (filled($row->done_by))
                                    <div class="text-xs text-zinc-500">{{ __('Done') }}: {{ $row->done_by }}</div>
                                @endif
                            @else
                                {{ $row->delivered_by ?? '-' }}
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $row->doctor_name ?? '-' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="text-center text-zinc-500">
                            {{ __('No deliveries found.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">
            {{ $this->deliveries->links() }}
        </div>
    </flux:card>
</div>
