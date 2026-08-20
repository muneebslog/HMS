<?php

use App\Enums\ClinicStation;
use App\Enums\StationType;
use App\Services\PatientFlowBoardService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Patient Flow')] class extends Component
{
    /**
     * @return array{
     *     stations: array<string, list<array{token_id: int, patient_name: string, mrn: ?string, token_number: int, service_name: string, stage_started_at: string, minutes_in_stage: int, stage_label: string}>>,
     *     aide_sessions: array<string, array{aide_name: ?string, expired: bool, minutes_remaining: ?int, status: string}>
     * }
     */
    #[Computed]
    public function board(): array
    {
        return app(PatientFlowBoardService::class)->board();
    }

    /**
     * @return list<ClinicStation>
     */
    #[Computed]
    public function columns(): array
    {
        return ClinicStation::cases();
    }

    public function patientCount(): int
    {
        return collect($this->board['stations'])->sum(fn (array $patients): int => count($patients));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-5" wire:poll.5s>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-1">
            <div class="flex items-center gap-2">
                <flux:heading size="xl">{{ __('Patient Flow') }}</flux:heading>
                <flux:badge color="green" size="sm">{{ __('Live') }}</flux:badge>
            </div>
            <flux:text class="text-zinc-500">
                {{ __('Medication visits only — vitals, doctor, payment, drip, ER, then done.') }}
            </flux:text>
        </div>
        <flux:badge color="zinc" size="lg">
            {{ trans_choice(':count patient|:count patients', $this->patientCount(), ['count' => $this->patientCount()]) }}
        </flux:badge>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        @foreach (StationType::cases() as $stationType)
            @php($session = $this->board['aide_sessions'][$stationType->value] ?? ['status' => 'none', 'aide_name' => null, 'minutes_remaining' => null, 'expired' => false])
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-2">
                    <flux:heading size="sm">{{ $stationType->label() }}</flux:heading>
                    @if ($session['status'] === 'active')
                        <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                    @elseif ($session['status'] === 'expired')
                        <flux:badge color="amber" size="sm">{{ __('Expired') }}</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm">{{ __('No login') }}</flux:badge>
                    @endif
                </div>
                <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                    @if ($session['aide_name'])
                        {{ $session['aide_name'] }}
                        @if ($session['status'] === 'active' && $session['minutes_remaining'] !== null)
                            <span class="text-zinc-500">· {{ __(':minutes min left', ['minutes' => $session['minutes_remaining']]) }}</span>
                        @endif
                    @else
                        <span class="text-zinc-500">{{ __('No health aide signed in') }}</span>
                    @endif
                </p>
            </div>
        @endforeach
    </div>

    <div class="flex min-h-0 flex-1 gap-3 overflow-x-auto pb-2">
        @foreach ($this->columns as $column)
            @php($patients = $this->board['stations'][$column->value] ?? [])
            <div @class([
                'flex min-h-80 w-72 shrink-0 flex-col rounded-2xl border shadow-sm',
                $column->columnClasses(),
            ])>
                <div class="flex items-start justify-between gap-2 border-b border-black/5 px-3 py-3 dark:border-white/10">
                    <div>
                        <flux:heading size="sm">{{ $column->label() }}</flux:heading>
                        <p class="mt-0.5 text-[11px] font-medium text-zinc-500">{{ $column->waitingLabel() }}</p>
                    </div>
                    <flux:badge :color="$column->badgeColor()" size="sm">{{ count($patients) }}</flux:badge>
                </div>
                <div class="flex flex-1 flex-col gap-2 overflow-y-auto p-2">
                    @forelse ($patients as $patient)
                        <div
                            wire:key="flow-{{ $column->value }}-{{ $patient['token_id'] }}"
                            class="rounded-xl border border-white/80 bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <p class="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ $patient['patient_name'] }}
                                </p>
                                <span class="font-mono text-lg font-bold leading-none tabular-nums text-zinc-900 dark:text-zinc-100">
                                    {{ $patient['token_number'] }}
                                </span>
                            </div>
                            <p class="mt-1 truncate text-xs text-zinc-500">
                                {{ $patient['service_name'] }}
                                @if ($patient['mrn'])
                                    · {{ $patient['mrn'] }}
                                @endif
                            </p>
                            <p class="mt-2 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                {{ $patient['stage_label'] }}
                            </p>
                            <p class="mt-1 text-[11px] text-zinc-500">
                                {{ __(':minutes min in stage', ['minutes' => $patient['minutes_in_stage']]) }}
                            </p>
                        </div>
                    @empty
                        <div class="flex flex-1 items-center justify-center px-2 py-10 text-center text-xs text-zinc-500">
                            {{ __('None') }}
                        </div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</div>
