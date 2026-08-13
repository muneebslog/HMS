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
     *     stations: array<string, list<array{token_id: int, patient_name: string, mrn: ?string, token_number: int, service_name: string, stage_started_at: string, minutes_in_stage: int}>>,
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
        return [
            ClinicStation::Vitals,
            ClinicStation::Doctor,
            ClinicStation::Reception,
            ClinicStation::Drip,
            ClinicStation::Er,
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6" wire:poll.10s>
    <div class="flex flex-col gap-1">
        <flux:heading size="xl">{{ __('Patient Flow') }}</flux:heading>
        <flux:text class="text-zinc-500">{{ __('Live view of where each patient is and which health aide is signed into ER / Drip.') }}</flux:text>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        @foreach (StationType::cases() as $stationType)
            @php($session = $this->board['aide_sessions'][$stationType->value] ?? ['status' => 'none', 'aide_name' => null, 'minutes_remaining' => null, 'expired' => false])
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
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

    <div class="grid gap-4 xl:grid-cols-5 lg:grid-cols-3 sm:grid-cols-2">
        @foreach ($this->columns as $column)
            @php($patients = $this->board['stations'][$column->value] ?? [])
            <div class="flex min-h-64 flex-col rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/60">
                <div class="flex items-center justify-between border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <flux:heading size="sm">{{ $column->label() }}</flux:heading>
                    <flux:badge color="zinc" size="sm">{{ count($patients) }}</flux:badge>
                </div>
                <div class="flex flex-1 flex-col gap-2 p-2">
                    @forelse ($patients as $patient)
                        <div
                            wire:key="flow-{{ $column->value }}-{{ $patient['token_id'] }}"
                            class="rounded-lg border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
                        >
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $patient['patient_name'] }}
                            </p>
                            <p class="truncate text-xs text-zinc-500">
                                {{ __('Token') }} #{{ $patient['token_number'] }}
                                · {{ $patient['service_name'] }}
                            </p>
                            @if ($patient['mrn'])
                                <p class="truncate text-xs text-zinc-500">{{ $patient['mrn'] }}</p>
                            @endif
                            <p class="mt-2 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                {{ __(':minutes min', ['minutes' => $patient['minutes_in_stage']]) }}
                            </p>
                        </div>
                    @empty
                        <div class="flex flex-1 items-center justify-center px-2 py-8 text-center text-xs text-zinc-500">
                            {{ __('None') }}
                        </div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</div>
