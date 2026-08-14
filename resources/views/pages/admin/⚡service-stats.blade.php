<?php

use App\Models\Service;
use App\Services\ServiceStatisticsService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Service Statistics')] class extends Component
{
    public ?int $selectedServiceId = null;

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $timeFrom = '00:00';

    public string $timeTo = '23:59';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->dateFrom = now()->subDays(6)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    /** @return Collection<int, Service> */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{
     *     total: int,
     *     average_per_day: float,
     *     highest_usage: array{date: string, total: int},
     *     lowest_usage: array{date: string, total: int},
     *     daily_usage: list<array{date: string, total: int}>
     * }|null
     */
    #[Computed]
    public function statistics(): ?array
    {
        $service = $this->selectedService();
        $dateFrom = $this->parseDate($this->dateFrom);
        $dateTo = $this->parseDate($this->dateTo);

        if (
            $service === null
            || $dateFrom === null
            || $dateTo === null
            || $dateFrom->isAfter($dateTo)
            || ! $this->hasValidTimes()
        ) {
            return null;
        }

        return app(ServiceStatisticsService::class)->forDateAndTimeRange(
            $service,
            $dateFrom,
            $dateTo,
            $this->timeFrom,
            $this->timeTo,
        );
    }

    #[Computed]
    public function hasInvalidRange(): bool
    {
        $dateFrom = $this->parseDate($this->dateFrom);
        $dateTo = $this->parseDate($this->dateTo);

        return $dateFrom === null
            || $dateTo === null
            || $dateFrom->isAfter($dateTo)
            || ! $this->hasValidTimes();
    }

    private function selectedService(): ?Service
    {
        return $this->selectedServiceId === null
            ? null
            : Service::query()->find($this->selectedServiceId);
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

    private function hasValidTimes(): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $this->timeFrom) === 1
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $this->timeTo) === 1;
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('Service Statistics') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500">
            {{ __('Compare daily service usage within a selected date and shift-time range.') }}
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

            <flux:input
                type="time"
                wire:model.live.change="timeFrom"
                label="{{ __('Time From') }}"
            />

            <flux:input
                type="time"
                wire:model.live.change="timeTo"
                label="{{ __('Time To') }}"
            />

            <flux:select
                wire:model.live="selectedServiceId"
                label="{{ __('Service') }}"
                placeholder="{{ __('Select a service') }}"
            >
                @foreach ($this->services as $service)
                    <flux:select.option wire:key="stats-service-{{ $service->id }}" :value="$service->id">
                        {{ $service->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($this->hasInvalidRange)
            <flux:text class="mt-4 text-sm text-red-600 dark:text-red-400">
                {{ __('Enter a valid date and time range. The start date must not be after the end date.') }}
            </flux:text>
        @endif
    </flux:card>

    @if ($this->statistics)
        @php($stats = $this->statistics)

        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="2">{{ $this->services->firstWhere('id', $selectedServiceId)?->name }}</flux:heading>
            <flux:text class="text-zinc-500">
                {{ $dateFrom }} — {{ $dateTo }} · {{ $timeFrom }} — {{ $timeTo }}
            </flux:text>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Total Usage') }}</flux:text>
                <flux:heading level="2" class="mt-1">{{ number_format($stats['total']) }}</flux:heading>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Average per Day') }}</flux:text>
                <flux:heading level="2" class="mt-1">{{ number_format($stats['average_per_day'], 1) }}</flux:heading>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Highest Usage') }}</flux:text>
                <flux:heading level="2" class="mt-1">{{ number_format($stats['highest_usage']['total']) }}</flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-500">{{ $stats['highest_usage']['date'] }}</flux:text>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Lowest Usage') }}</flux:text>
                <flux:heading level="2" class="mt-1">{{ number_format($stats['lowest_usage']['total']) }}</flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-500">{{ $stats['lowest_usage']['date'] }}</flux:text>
            </flux:card>
        </div>

        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('Daily Usage') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Usage') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($stats['daily_usage'] as $day)
                        <flux:table.row wire:key="stats-day-{{ $day['date'] }}">
                            <flux:table.cell>{{ $day['date'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($day['total']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @elseif ($selectedServiceId === null && ! $this->hasInvalidRange)
        <flux:card class="py-12 text-center">
            <flux:heading level="2">{{ __('Choose a service') }}</flux:heading>
            <flux:text class="mt-2 text-zinc-500">{{ __('Usage statistics will appear here for the selected date and time range.') }}</flux:text>
        </flux:card>
    @endif
</div>