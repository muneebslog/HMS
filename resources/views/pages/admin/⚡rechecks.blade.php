<?php

use App\Enums\DripLineStatus;
use App\Models\DoctorRecheck;
use App\Models\MedicationOrderDrip;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Recheck Timers')] class extends Component
{
    use WithPagination;

    public string $statusFilter = 'active';

    public string $search = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Paginated doctor rechecks for admin monitoring.
     *
     * @return LengthAwarePaginator<int, DoctorRecheck>
     */
    #[Computed]
    public function rechecks(): LengthAwarePaginator
    {
        $query = DoctorRecheck::query()
            ->with(['patient', 'setBy', 'queueToken.vital', 'queueToken.serviceQueue.service'])
            ->latest('due_at');

        match ($this->statusFilter) {
            'on_timer' => $query->pending()->where('due_at', '>', now()),
            'awaiting_vitals' => $query->awaitingVitals(),
            'vitals_redone' => $query->pending()->whereNotNull('vitals_redone_at'),
            'cleared' => $query->whereNotNull('acknowledged_at'),
            'active' => $query->pending(),
            default => null,
        };

        if (filled($this->search)) {
            $search = $this->search;
            $query->whereHas('patient', function ($patientQuery) use ($search): void {
                $patientQuery->where('name', 'like', '%'.$search.'%')
                    ->orWhere('mrn', 'like', '%'.$search.'%');
            });
        }

        return $query->paginate(20);
    }

    /**
     * Started drips awaiting check or completion.
     *
     * @return Collection<int, MedicationOrderDrip>
     */
    #[Computed]
    public function dripChecks(): Collection
    {
        return MedicationOrderDrip::query()
            ->with([
                'startedByHealthAide',
                'medicationOrder.patient',
                'medicationOrder.queueToken.serviceQueue.service',
            ])
            ->where('status', DripLineStatus::Started)
            ->orderBy('check_due_at')
            ->get();
    }

    public function markDripDone(int $dripId): void
    {
        $drip = MedicationOrderDrip::query()->find($dripId);

        if ($drip === null || $drip->status !== DripLineStatus::Started) {
            Flux::toast(variant: 'danger', text: __('Drip is no longer started.'));
            unset($this->dripChecks);

            return;
        }

        $drip->update([
            'status' => DripLineStatus::Done,
            'done_at' => now(),
            'done_by_user_id' => auth()->id(),
        ]);

        unset($this->dripChecks);

        Flux::toast(variant: 'success', text: __('Drip marked done.'));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6" wire:poll.10s>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading level="1">{{ __('Recheck Timers') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Doctor vitals rechecks and drip check reminders.') }}</flux:text>
        </div>
    </div>

    <flux:card>
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="2">{{ __('Drip checks') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Started drips with a 30-minute check timer.') }}</flux:text>
            </div>
            <flux:badge color="zinc" size="lg">{{ $this->dripChecks->count() }}</flux:badge>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Patient') }}</flux:table.column>
                <flux:table.column>{{ __('Drip') }}</flux:table.column>
                <flux:table.column>{{ __('Started by') }}</flux:table.column>
                <flux:table.column>{{ __('Check due') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->dripChecks as $drip)
                    @php($overdue = $drip->isCheckDue())
                    <flux:table.row wire:key="admin-drip-check-{{ $drip->id }}">
                        <flux:table.cell>
                            <div class="font-medium">{{ $drip->medicationOrder?->patient?->name ?? __('Unknown') }}</div>
                            <div class="text-xs text-zinc-500">
                                {{ $drip->medicationOrder?->patient?->mrn ?? __('No MRN') }}
                                @if ($drip->medicationOrder?->queueToken?->serviceQueue?->service)
                                    · {{ $drip->medicationOrder->queueToken->serviceQueue->service->name }}
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $drip->name }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $drip->startedByHealthAide?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($drip->check_due_at)
                                {{ $drip->check_due_at->timezone(config('app.timezone'))->format('d M, h:i A') }}
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$overdue ? 'amber' : 'sky'">
                                {{ $overdue ? __('Check due') : __('On timer') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                size="sm"
                                variant="primary"
                                wire:click="markDripDone({{ $drip->id }})"
                                wire:confirm="{{ __('Mark this drip as done?') }}"
                            >
                                {{ __('Mark done') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center text-zinc-500">
                            {{ __('No started drips awaiting check.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:card>
        <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="2">{{ __('Patients on timer') }}</flux:heading>
                <flux:text class="mt-1">{{ __('See who is waiting on a recheck and whether vitals were redone after the timer.') }}</flux:text>
            </div>

            <div class="flex flex-col gap-4 sm:flex-row">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search patient / MRN...') }}"
                    class="w-full sm:w-56"
                />

                <flux:select wire:model.live="statusFilter" class="w-full sm:w-48">
                    <option value="active">{{ __('Active') }}</option>
                    <option value="on_timer">{{ __('On timer') }}</option>
                    <option value="awaiting_vitals">{{ __('Awaiting vitals') }}</option>
                    <option value="vitals_redone">{{ __('Vitals redone') }}</option>
                    <option value="cleared">{{ __('Cleared') }}</option>
                    <option value="all">{{ __('All') }}</option>
                </flux:select>
            </div>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Patient') }}</flux:table.column>
                <flux:table.column>{{ __('Note') }}</flux:table.column>
                <flux:table.column>{{ __('Set by') }}</flux:table.column>
                <flux:table.column>{{ __('Timer') }}</flux:table.column>
                <flux:table.column>{{ __('Time left') }}</flux:table.column>
                <flux:table.column>{{ __('Due') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Vitals redone') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->rechecks as $recheck)
                    <flux:table.row wire:key="admin-recheck-{{ $recheck->id }}">
                        <flux:table.cell>
                            <div class="font-medium">{{ $recheck->patient?->name ?? __('Unknown') }}</div>
                            <div class="text-xs text-zinc-500">
                                {{ $recheck->patient?->mrn ?? __('No MRN') }}
                                @if ($recheck->queueToken?->serviceQueue?->service)
                                    · {{ $recheck->queueToken->serviceQueue->service->name }}
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $recheck->note ?: '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $recheck->setBy?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $recheck->minutes }} {{ __('min') }}</flux:table.cell>
                        <flux:table.cell>
                            <span @class([
                                'font-medium',
                                'text-sky-700 dark:text-sky-400' => $recheck->timerStatus() === 'on_timer',
                                'text-amber-700 dark:text-amber-400' => in_array($recheck->timerStatus(), ['awaiting_vitals'], true),
                                'text-zinc-500' => in_array($recheck->timerStatus(), ['cleared', 'vitals_redone'], true),
                            ])>
                                {{ $recheck->timeRemainingLabel() }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $recheck->due_at->timezone(config('app.timezone'))->format('d M, h:i A') }}
                        </flux:table.cell>
                        <flux:table.cell>
                            @php($status = $recheck->timerStatus())
                            <flux:badge
                                size="sm"
                                color="{{ match ($status) {
                                    'on_timer' => 'sky',
                                    'awaiting_vitals' => 'amber',
                                    'vitals_redone' => 'green',
                                    'cleared' => 'zinc',
                                    default => 'zinc',
                                } }}"
                            >
                                {{ $recheck->timerStatusLabel() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($recheck->hasVitalsRedone())
                                <div class="font-medium text-green-700 dark:text-green-400">{{ __('Yes') }}</div>
                                <div class="text-xs text-zinc-500">
                                    {{ $recheck->vitals_redone_at->timezone(config('app.timezone'))->format('d M, h:i A') }}
                                </div>
                            @elseif ($recheck->isDue())
                                <span class="font-medium text-amber-700 dark:text-amber-400">{{ __('No') }}</span>
                            @else
                                <span class="text-zinc-500">{{ __('Waiting') }}</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="text-center text-zinc-500">
                            {{ __('No recheck timers found.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">
            {{ $this->rechecks->links() }}
        </div>
    </flux:card>
</div>
