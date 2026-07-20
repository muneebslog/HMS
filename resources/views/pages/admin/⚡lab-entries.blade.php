<?php

use App\Enums\LabApiStatus;
use App\Models\LabInvoice;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lab Entries')] class extends Component
{
    public string $statusFilter = 'all';

    public string $keyword = '';

    public ?int $selectedInvoiceId = null;

    public bool $showDetailModal = false;

    /**
     * Get a paginated list of lab invoices with their patients and API logs.
     */
    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        $query = LabInvoice::with(['patient', 'labApiLog'])->latest();

        if ($this->statusFilter !== 'all' && in_array($this->statusFilter, LabApiStatus::values(), true)) {
            $query->whereHas('labApiLog', fn ($q) => $q->where('status', $this->statusFilter));
        }

        if (filled($this->keyword)) {
            $term = '%'.$this->keyword.'%';
            $query->where(function ($q) use ($term) {
                $q->where('invoice_number', 'like', $term)
                    ->orWhereHas('patient', function ($patientQuery) use ($term) {
                        $patientQuery->where('name', 'like', $term)
                            ->orWhere('phone', 'like', $term);
                    });
            });
        }

        return $query->paginate(20);
    }

    /**
     * Get the invoice currently selected for detail viewing.
     */
    #[Computed]
    public function selectedInvoice(): ?LabInvoice
    {
        if ($this->selectedInvoiceId === null) {
            return null;
        }

        return LabInvoice::with(['patient', 'labApiLog', 'items'])->find($this->selectedInvoiceId);
    }

    /**
     * Open the detail modal for the selected invoice.
     */
    public function viewInvoice(int $id): void
    {
        $this->selectedInvoiceId = $id;
        $this->showDetailModal = true;
    }

    /**
     * Close the detail modal and reset its state.
     */
    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedInvoiceId = null;
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Lab Entries') }}</flux:heading>
        </div>

        <flux:card>
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Invoices') }}</flux:heading>

                <div class="flex flex-col gap-4 sm:flex-row">
                    <flux:input
                        wire:model.live.debounce.300ms="keyword"
                        placeholder="{{ __('Search invoice, patient, phone...') }}"
                        class="w-full sm:w-64"
                    />

                    <flux:select wire:model.live="statusFilter" class="w-full sm:w-44">
                        <option value="all">{{ __('All statuses') }}</option>
                        @foreach (App\Enums\LabApiStatus::cases() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Phone') }}</flux:table.column>
                    <flux:table.column>{{ __('Total') }}</flux:table.column>
                    <flux:table.column>{{ __('Invoice Status') }}</flux:table.column>
                    <flux:table.column>{{ __('API Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Sent At') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->invoices as $invoice)
                        @php
                            $log = $invoice->labApiLog;
                            $labCaseUrl = $log?->lab_case_url ?? rtrim(config('services.lab.url'), '/').'/my-visit/'.$invoice->invoice_number;
                        @endphp
                        <flux:table.row wire:key="lab-entry-{{ $invoice->id }}">
                            <flux:table.cell>{{ $invoice->invoice_number }}</flux:table.cell>
                            <flux:table.cell>{{ $invoice->patient?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $invoice->patient?->phone ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($invoice->total, 2) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ ucfirst($invoice->status) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($log === null)
                                    <flux:badge size="sm" color="zinc">{{ __('Not sent') }}</flux:badge>
                                @elseif ($log->status === App\Enums\LabApiStatus::Sent)
                                    <flux:badge size="sm" color="green">{{ $log->status->label() }}</flux:badge>
                                @elseif ($log->status === App\Enums\LabApiStatus::Failed)
                                    <flux:tooltip :content="filled($log->error_message) ? Str::limit($log->error_message, 200) : (filled($log->response_body) ? Str::limit($log->response_body, 200) : __('Click View for details'))" position="top">
                                        <flux:badge size="sm" color="red">{{ $log->status->label() }}</flux:badge>
                                    </flux:tooltip>
                                @else
                                    <flux:badge size="sm" color="amber">{{ $log->status->label() }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $log?->sent_at?->format('Y-m-d H:i') ?? '-' }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <div class="flex justify-end gap-2">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="eye"
                                        wire:click="viewInvoice({{ $invoice->id }})"
                                    >
                                        {{ __('View') }}
                                    </flux:button>

                                    @if (filled(config('services.lab.url')))
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="arrow-top-right-on-square"
                                            href="{{ $labCaseUrl }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            {{ __('Lab') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8" class="text-center text-zinc-500">
                                {{ __('No lab entries found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->invoices->links() }}
            </div>
        </flux:card>
    </div>

    <flux:modal wire:model="showDetailModal" class="w-full max-w-2xl">
        @if ($this->selectedInvoice)
            @php
                $log = $this->selectedInvoice->labApiLog;
            @endphp
            <flux:heading level="2">{{ __('Lab Entry Details') }}</flux:heading>

            <div class="mt-4 space-y-3 text-sm">
                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Invoice #') }}</flux:text>
                    <flux:text class="col-span-2">{{ $this->selectedInvoice->invoice_number }}</flux:text>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Patient') }}</flux:text>
                    <flux:text class="col-span-2">{{ $this->selectedInvoice->patient?->name ?? '-' }}</flux:text>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Phone') }}</flux:text>
                    <flux:text class="col-span-2">{{ $this->selectedInvoice->patient?->phone ?? '-' }}</flux:text>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Total') }}</flux:text>
                    <flux:text class="col-span-2">{{ number_format($this->selectedInvoice->total, 2) }}</flux:text>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Invoice Status') }}</flux:text>
                    <flux:text class="col-span-2">{{ ucfirst($this->selectedInvoice->status) }}</flux:text>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('API Status') }}</flux:text>
                    <flux:text class="col-span-2">{{ $log?->status?->label() ?? __('Not sent') }}</flux:text>
                </div>

                @if ($log?->http_status !== null)
                    <div class="grid grid-cols-3 gap-2">
                        <flux:text class="text-zinc-500">{{ __('HTTP Status') }}</flux:text>
                        <flux:text class="col-span-2">{{ $log->http_status }}</flux:text>
                    </div>
                @endif

                <div class="grid grid-cols-3 gap-2">
                    <flux:text class="text-zinc-500">{{ __('Sent At') }}</flux:text>
                    <flux:text class="col-span-2">{{ $log?->sent_at?->format('Y-m-d H:i:s') ?? '-' }}</flux:text>
                </div>

                @if ($log !== null && filled($log->lab_case_url))
                    <div class="grid grid-cols-3 gap-2">
                        <flux:text class="text-zinc-500">{{ __('Lab Case Link') }}</flux:text>
                        <div class="col-span-2">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="arrow-top-right-on-square"
                                href="{{ $log->lab_case_url }}"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ __('Open in lab app') }}
                            </flux:button>
                        </div>
                    </div>
                @endif

                @if ($log !== null && filled($log->request_payload))
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Request Payload') }}</flux:text>
                        <pre class="mt-1 max-h-48 overflow-auto rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-900">{{ json_encode($log->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                @endif

                @if ($log !== null && filled($log->response_body))
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Response Body') }}</flux:text>
                        <pre class="mt-1 max-h-48 overflow-auto rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-900">{{ $log->response_body }}</pre>
                    </div>
                @endif

                @if ($log !== null && filled($log->error_message))
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Error Message') }}</flux:text>
                        <flux:text class="mt-1 block whitespace-pre-wrap text-red-600 dark:text-red-400">{{ $log->error_message }}</flux:text>
                    </div>
                @endif

                @if ($this->selectedInvoice->items->isNotEmpty())
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Tests') }}</flux:text>
                        <ul class="mt-1 list-inside list-disc">
                            @foreach ($this->selectedInvoice->items as $item)
                                <li>{{ $item->test_name }} {{ $item->test_code ? '('.$item->test_code.')' : '' }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeDetailModal">
                    {{ __('Close') }}
                </flux:button>
            </div>
        @endif
    </flux:modal>
</div>
