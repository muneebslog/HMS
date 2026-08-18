<?php

use App\Services\MedicationDeliveryLogService;
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

    public string $typeFilter = 'all';

    public string $keyword = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->dateFrom = now()->subDays(6)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
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
        $dateFrom = $this->parseDate($this->dateFrom);
        $dateTo = $this->parseDate($this->dateTo);

        if ($dateFrom === null || $dateTo === null || $dateFrom->isAfter($dateTo)) {
            return new LengthAwarePaginator([], 0, 20);
        }

        return app(MedicationDeliveryLogService::class)->paginate(
            $dateFrom,
            $dateTo,
            $this->typeFilter,
            $this->keyword,
        );
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
            {{ __('Medicines, injections, and drips delivered or started at ER and drip stations.') }}
        </flux:text>
    </div>

    <flux:card>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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
