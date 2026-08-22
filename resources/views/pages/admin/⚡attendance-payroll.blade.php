<?php

use App\Models\HealthAide;
use App\Services\PayrollReportService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Attendance Payroll')] class extends Component
{
    public int $year;

    public int $month;

    public string $stationFilter = '';

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin() && ! auth()->user()?->isManagement()) {
            abort(403);
        }

        $this->year = now()->year;
        $this->month = now()->month;
    }

    #[Computed]
    public function summary()
    {
        return app(PayrollReportService::class)->monthlySummary(
            $this->year,
            $this->month,
            $this->stationFilter !== '' ? $this->stationFilter : null,
        );
    }

    public function exportCsv(PayrollReportService $payrollReportService): StreamedResponse
    {
        $rows = $payrollReportService->monthlySummaryCsv(
            $this->year,
            $this->month,
            $this->stationFilter !== '' ? $this->stationFilter : null,
        );

        $filename = sprintf('health-aide-payroll-%04d-%02d.csv', $this->year, $this->month);

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading level="1">{{ __('Monthly Payroll Report') }}</flux:heading>
        <flux:button wire:click="exportCsv" icon="arrow-down-tray">{{ __('Export CSV') }}</flux:button>
    </div>

    <flux:card>
        <div class="mb-4 flex flex-wrap gap-4">
            <flux:input wire:model.live="year" type="number" label="{{ __('Year') }}" class="w-32" />
            <flux:input wire:model.live="month" type="number" min="1" max="12" label="{{ __('Month') }}" class="w-32" />
            <flux:input wire:model.live="stationFilter" label="{{ __('Station Filter') }}" placeholder="{{ __('All stations') }}" class="w-48" />
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Health Aide') }}</flux:table.column>
                <flux:table.column>{{ __('Days') }}</flux:table.column>
                <flux:table.column>{{ __('Regular Hrs') }}</flux:table.column>
                <flux:table.column>{{ __('OT Hrs') }}</flux:table.column>
                <flux:table.column>{{ __('Payable Hrs') }}</flux:table.column>
                <flux:table.column>{{ __('Lates') }}</flux:table.column>
                <flux:table.column>{{ __('Absences') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->summary as $row)
                    <flux:table.row wire:key="payroll-{{ $row['health_aide_id'] }}">
                        <flux:table.cell>{{ $row['health_aide_name'] }}</flux:table.cell>
                        <flux:table.cell>{{ $row['days_worked'] }}</flux:table.cell>
                        <flux:table.cell>{{ $row['regular_hours'] }}</flux:table.cell>
                        <flux:table.cell>{{ $row['overtime_hours'] }}</flux:table.cell>
                        <flux:table.cell>{{ $row['payable_hours'] }}</flux:table.cell>
                        <flux:table.cell>{{ $row['late_count'] }}</flux:table.cell>
                        <flux:table.cell>{{ $row['absent_count'] }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="7">{{ __('No payroll data for this month.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
