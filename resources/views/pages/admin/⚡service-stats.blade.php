<?php

use App\Models\Service;
use App\Models\Shift;
use App\Services\ServiceStatisticsService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Service Statistics')] class extends Component
{
    public ?int $selectedShiftId = null;

    public ?int $selectedServiceId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updatedSelectedShiftId(): void
    {
        $this->selectedServiceId = null;
        unset($this->services, $this->statistics);
    }

    public function updatedSelectedServiceId(): void
    {
        unset($this->statistics);
    }

    /** @return Collection<int, Shift> */
    #[Computed]
    public function shifts(): Collection
    {
        return Shift::query()
            ->with('user:id,name')
            ->latest('opened_at')
            ->get(['id', 'user_id', 'opened_at', 'closed_at', 'status']);
    }

    /** @return Collection<int, Service> */
    #[Computed]
    public function services(): Collection
    {
        $shift = $this->selectedShift();

        if ($shift === null) {
            return new Collection;
        }

        return app(ServiceStatisticsService::class)->servicesForShift($shift);
    }

    /**
     * @return array{
     *     total_visits: int,
     *     unique_patients: int,
     *     revenue: float,
     *     average_wait_minutes: ?int,
     *     statuses: array<string, int>,
     *     doctor_breakdown: list<array{doctor_name: string, visits: int, revenue: float}>
     * }|null
     */
    #[Computed]
    public function statistics(): ?array
    {
        $shift = $this->selectedShift();

        if ($shift === null || $this->selectedServiceId === null) {
            return null;
        }

        $service = $this->services->firstWhere('id', $this->selectedServiceId);

        if ($service === null) {
            return null;
        }

        return app(ServiceStatisticsService::class)->forShiftAndService($shift, $service);
    }

    private function selectedShift(): ?Shift
    {
        if ($this->selectedShiftId === null) {
            return null;
        }

        return Shift::query()->find($this->selectedShiftId);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('Service Statistics') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500">
            {{ __('Review patient flow and revenue for one service during a shift.') }}
        </flux:text>
    </div>

    <flux:card>
        <div class="grid gap-4 md:grid-cols-2">
            <flux:select
                wire:model.live="selectedShiftId"
                label="{{ __('Shift') }}"
                placeholder="{{ __('Select a shift') }}"
            >
                @foreach ($this->shifts as $shift)
                    <flux:select.option wire:key="stats-shift-{{ $shift->id }}" :value="$shift->id">
                        {{ $shift->opened_at->format('Y-m-d H:i') }}
                        · {{ $shift->user->name }}
                        · {{ ucfirst($shift->status) }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select
                wire:model.live="selectedServiceId"
                label="{{ __('Service') }}"
                placeholder="{{ $selectedShiftId ? __('Select a service') : __('Select a shift first') }}"
                :disabled="$selectedShiftId === null"
            >
                @foreach ($this->services as $service)
                    <flux:select.option wire:key="stats-service-{{ $service->id }}" :value="$service->id">
                        {{ $service->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($selectedShiftId && $this->services->isEmpty())
            <flux:text class="mt-4 text-sm text-zinc-500">
                {{ __('No services were recorded during this shift.') }}
            </flux:text>
        @endif
    </flux:card>

    @if ($this->statistics)
        @php($stats = $this->statistics)

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Total Visits') }}</flux:text>
                <flux:heading level="2" class="mt-1">{{ number_format($stats['total_visits']) }}</flux:heading>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Unique Patients') }}</flux:text>
                <flux:heading level="2" class="mt-1">{{ number_format($stats['unique_patients']) }}</flux:heading>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Revenue') }}</flux:text>
                <flux:heading level="2" class="mt-1 text-green-700 dark:text-green-400">
                    {{ number_format($stats['revenue'], 2) }}
                </flux:heading>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Average Wait') }}</flux:text>
                <flux:heading level="2" class="mt-1">
                    {{ $stats['average_wait_minutes'] === null ? '—' : __(':minutes min', ['minutes' => $stats['average_wait_minutes']]) }}
                </flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-500">{{ __('Arrival to first call') }}</flux:text>
            </flux:card>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <flux:card>
                <flux:heading level="2" class="mb-4">{{ __('Token Status') }}</flux:heading>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach (['waiting', 'serving', 'served', 'reserved', 'skipped'] as $status)
                        <div wire:key="stats-status-{{ $status }}" class="rounded-lg bg-zinc-100 p-3 dark:bg-zinc-800">
                            <flux:text class="text-sm text-zinc-500">{{ __(ucfirst($status)) }}</flux:text>
                            <flux:heading level="3" class="mt-1">{{ number_format($stats['statuses'][$status] ?? 0) }}</flux:heading>
                        </div>
                    @endforeach
                </div>
            </flux:card>

            <flux:card>
                <flux:heading level="2" class="mb-4">{{ __('Doctor Breakdown') }}</flux:heading>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                        <flux:table.column>{{ __('Visits') }}</flux:table.column>
                        <flux:table.column>{{ __('Revenue') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($stats['doctor_breakdown'] as $index => $doctor)
                            <flux:table.row wire:key="stats-doctor-{{ $index }}">
                                <flux:table.cell>{{ $doctor['doctor_name'] }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($doctor['visits']) }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($doctor['revenue'], 2) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="3" class="text-center text-zinc-500">
                                    {{ __('No service activity found.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        </div>
    @elseif ($selectedServiceId === null)
        <flux:card class="py-12 text-center">
            <flux:heading level="2">{{ __('Choose a shift and service') }}</flux:heading>
            <flux:text class="mt-2 text-zinc-500">{{ __('Statistics will appear here after both filters are selected.') }}</flux:text>
        </flux:card>
    @endif
</div>