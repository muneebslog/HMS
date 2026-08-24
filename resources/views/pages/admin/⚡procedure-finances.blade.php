<?php

use App\Services\ProcedureFinanceReportService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Procedure Finances')] class extends Component
{
    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo = now()->toDateString();
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function report(): ?array
    {
        $dateFrom = $this->parseDate($this->dateFrom);
        $dateTo = $this->parseDate($this->dateTo);

        if ($dateFrom === null || $dateTo === null || $dateFrom->isAfter($dateTo)) {
            return null;
        }

        return app(ProcedureFinanceReportService::class)->forDateRange($dateFrom, $dateTo);
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
        <flux:heading level="1">{{ __('Procedure Finances') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500">
            {{ __('Billed, collected, and outstanding amounts for procedures created in the selected date range.') }}
        </flux:text>
    </div>

    <flux:card>
        <div class="grid gap-4 sm:grid-cols-2">
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
        </div>

        @if ($this->hasInvalidRange)
            <flux:text class="mt-4 text-sm text-red-600 dark:text-red-400">
                {{ __('Enter a valid date range. The start date must not be after the end date.') }}
            </flux:text>
        @endif
    </flux:card>

    @if ($this->report)
        @php($report = $this->report)

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Cases') }}</flux:text>
                <flux:heading level="2" class="mt-1">{{ number_format($report['cases']) }}</flux:heading>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Billed') }}</flux:text>
                <flux:heading level="2" class="mt-1">{{ number_format($report['billed'], 2) }}</flux:heading>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Collected') }}</flux:text>
                <flux:heading level="2" class="mt-1 text-green-700 dark:text-green-400">{{ number_format($report['collected'], 2) }}</flux:heading>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Outstanding') }}</flux:text>
                <flux:heading level="2" class="mt-1 {{ $report['outstanding'] > 0 ? 'text-red-700 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">
                    {{ number_format($report['outstanding'], 2) }}
                </flux:heading>
            </flux:card>
        </div>

        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('By Procedure') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Procedure') }}</flux:table.column>
                    <flux:table.column>{{ __('Cases') }}</flux:table.column>
                    <flux:table.column>{{ __('Billed') }}</flux:table.column>
                    <flux:table.column>{{ __('Collected') }}</flux:table.column>
                    <flux:table.column>{{ __('Outstanding') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($report['by_type'] as $row)
                        <flux:table.row wire:key="procedure-type-{{ $row['procedure_type_id'] ?? $row['name'] }}">
                            <flux:table.cell>{{ $row['name'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['cases']) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['billed'], 2) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['collected'], 2) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($row['outstanding'], 2) }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('No procedures in this date range.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('Procedure Details') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Procedure') }}</flux:table.column>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                    <flux:table.column>{{ __('Billed') }}</flux:table.column>
                    <flux:table.column>{{ __('Collected') }}</flux:table.column>
                    <flux:table.column>{{ __('Outstanding') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($report['procedures'] as $procedure)
                        @php($paid = (float) ($procedure->paid_amount ?? 0))
                        <flux:table.row wire:key="procedure-{{ $procedure->id }}">
                            <flux:table.cell>{{ $procedure->created_at?->timezone(config('app.timezone'))->format('d M Y') }}</flux:table.cell>
                            <flux:table.cell>{{ $procedure->procedureType?->name ?? $procedure->name }}</flux:table.cell>
                            <flux:table.cell>
                                <div>{{ $procedure->patient?->name ?? __('Unknown') }}</div>
                                <div class="text-xs text-zinc-500">{{ $procedure->patient?->mrn ?? __('No MRN') }}</div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $procedure->doctor?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ number_format((float) $procedure->full_amount, 2) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($paid, 2) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format((float) $procedure->full_amount - $paid, 2) }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="text-center text-zinc-500">{{ __('No procedures in this date range.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</div>
