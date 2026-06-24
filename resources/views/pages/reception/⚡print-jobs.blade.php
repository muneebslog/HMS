<?php

use App\Enums\PrintJobStatus;
use App\Models\PrintJob;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Print Jobs')] class extends Component
{
    public string $statusFilter = 'all';

    public int $perPage = 20;

    /**
     * Get the filtered print jobs.
     *
     * @return Collection<int, PrintJob>
     */
    #[Computed]
    public function jobs(): Collection
    {
        $query = PrintJob::with(['invoice.patient', 'labInvoice.patient'])
            ->latest();

        if ($this->statusFilter !== 'all' && in_array($this->statusFilter, PrintJobStatus::values(), true)) {
            $query->where('status', $this->statusFilter);
        }

        return $query->limit($this->perPage)->get();
    }

    /**
     * Reset a failed print job back to pending.
     */
    public function retry(int $jobId): void
    {
        $job = PrintJob::find($jobId);

        if ($job === null) {
            return;
        }

        $job->update([
            'status' => PrintJobStatus::Pending,
            'failed_at' => null,
            'error_message' => null,
        ]);
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Print Jobs') }}</flux:heading>
        </div>

        <flux:card>
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Recent Jobs') }}</flux:heading>

                <flux:select wire:model.live="statusFilter" class="w-full sm:w-auto">
                    <option value="all">{{ __('All statuses') }}</option>
                    @foreach (App\Enums\PrintJobStatus::cases() as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </flux:select>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('ID') }}</flux:table.column>
                    <flux:table.column>{{ __('Type') }}</flux:table.column>
                    <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Attempts') }}</flux:table.column>
                    <flux:table.column>{{ __('Created') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->jobs as $job)
                        <flux:table.row wire:key="print-job-{{ $job->id }}">
                            <flux:table.cell>#{{ $job->id }}</flux:table.cell>
                            <flux:table.cell>{{ ucfirst(str_replace('_', ' ', $job->payload['type'] ?? 'invoice')) }}</flux:table.cell>
                            <flux:table.cell>{{ $job->invoice?->invoice_number ?? $job->labInvoice?->invoice_number ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $job->invoice?->patient?->name ?? $job->labInvoice?->patient?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($job->status === App\Enums\PrintJobStatus::Pending)
                                    <flux:badge size="sm" color="amber">{{ $job->status->label() }}</flux:badge>
                                @elseif ($job->status === App\Enums\PrintJobStatus::Printed)
                                    <flux:badge size="sm" color="green">{{ $job->status->label() }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="red">{{ $job->status->label() }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $job->attempts }}</flux:table.cell>
                            <flux:table.cell>{{ $job->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                @if ($job->status === App\Enums\PrintJobStatus::Failed)
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="arrow-path"
                                        wire:click="retry({{ $job->id }})"
                                    />
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8" class="text-center text-zinc-500">
                                {{ __('No print jobs found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>
</div>
