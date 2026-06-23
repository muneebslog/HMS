<?php

use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Invoices')] class extends Component
{
    public ?int $viewingInvoiceId = null;

    public ?string $viewingType = null;

    public bool $showViewModal = false;

    /**
     * Get the currently open shift for the user.
     */
    #[Computed]
    public function currentShift(): ?Shift
    {
        return Shift::currentForUser(auth()->id());
    }

    /**
     * Get the walk-in invoices with their patient and items.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        return Invoice::with(['patient', 'items'])->latest()->get();
    }

    /**
     * Get the lab invoices with their patient and items.
     *
     * @return Collection<int, LabInvoice>
     */
    #[Computed]
    public function labInvoices(): Collection
    {
        return LabInvoice::with(['patient', 'items'])->latest()->get();
    }

    /**
     * Get the total of all walk-in invoices.
     */
    #[Computed]
    public function totalWalkInInvoices(): float
    {
        return $this->invoices->sum('total');
    }

    /**
     * Get the total of all lab invoices.
     */
    #[Computed]
    public function totalLabInvoices(): float
    {
        return $this->labInvoices->sum('total');
    }

    /**
     * Open the detail modal for the selected invoice.
     */
    public function viewInvoice(int $id, string $type): void
    {
        $this->viewingInvoiceId = $id;
        $this->viewingType = $type;
        $this->showViewModal = true;
    }

    /**
     * Close the detail modal and reset its state.
     */
    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewingInvoiceId = null;
        $this->viewingType = null;
    }

    /**
     * Get the invoice currently being viewed.
     */
    #[Computed]
    public function viewedInvoice(): ?Invoice
    {
        if ($this->viewingType !== 'walkin' || $this->viewingInvoiceId === null) {
            return null;
        }

        return Invoice::with(['patient', 'items'])->find($this->viewingInvoiceId);
    }

    /**
     * Get the lab invoice currently being viewed.
     */
    #[Computed]
    public function viewedLabInvoice(): ?LabInvoice
    {
        if ($this->viewingType !== 'lab' || $this->viewingInvoiceId === null) {
            return null;
        }

        return LabInvoice::with(['patient', 'items'])->find($this->viewingInvoiceId);
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Invoices') }}</flux:heading>
        </div>

        @if ($this->currentShift)
            <flux:card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Current Shift') }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Opened at') }}: {{ $this->currentShift->opened_at->format('Y-m-d H:i') }}
                        </flux:text>
                    </div>
                    <flux:badge size="sm" color="green">{{ __('Open') }}</flux:badge>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Opening Balance') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->currentShift->opening_balance, 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Walk-in Sales') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->currentShift->totalWalkInSales(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Lab Sales') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->currentShift->totalLabSales(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Total Sales') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->currentShift->totalSales(), 2) }}</flux:text>
                    </div>
                </div>
            </flux:card>
        @else
            <flux:card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('No Open Shift') }}</flux:heading>
                        <flux:text class="text-zinc-500">{{ __('Open a shift to start creating invoices.') }}</flux:text>
                    </div>
                    <flux:button variant="primary" icon="lock-open" :href="route('reception.shift')" wire:navigate>
                        {{ __('Open Shift') }}
                    </flux:button>
                </div>
            </flux:card>
        @endif

        <flux:card>
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Walk-in Invoices') }}</flux:heading>
                <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($this->totalWalkInInvoices, 2) }}</flux:text>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Total') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->invoices as $invoice)
                        <flux:table.row wire:key="invoice-{{ $invoice->id }}">
                            <flux:table.cell>{{ $invoice->invoice_number }}</flux:table.cell>
                            <flux:table.cell>{{ $invoice->patient->name }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($invoice->total, 2) }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($invoice->status === 'paid')
                                    <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $invoice->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="eye"
                                    wire:click="viewInvoice({{ $invoice->id }}, 'walkin')"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="printer"
                                    x-on:click="window.print()"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center text-zinc-500">
                                {{ __('No walk-in invoices found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <flux:card>
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Lab Invoices') }}</flux:heading>
                <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($this->totalLabInvoices, 2) }}</flux:text>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Total') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->labInvoices as $invoice)
                        <flux:table.row wire:key="lab-invoice-{{ $invoice->id }}">
                            <flux:table.cell>{{ $invoice->invoice_number }}</flux:table.cell>
                            <flux:table.cell>{{ $invoice->patient->name }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($invoice->total, 2) }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($invoice->status === 'paid')
                                    <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $invoice->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="eye"
                                    wire:click="viewInvoice({{ $invoice->id }}, 'lab')"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="printer"
                                    x-on:click="window.print()"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center text-zinc-500">
                                {{ __('No lab invoices found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>

    <flux:modal wire:model="showViewModal" class="w-full max-w-2xl">
        @if ($this->viewingType === 'walkin' && $this->viewedInvoice)
            <div class="print:p-0">
                <flux:heading level="2">{{ __('Invoice :number', ['number' => $this->viewedInvoice->invoice_number]) }}</flux:heading>

                <div class="mt-6 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Patient') }}</flux:text>
                        <flux:text>{{ $this->viewedInvoice->patient->name }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Status') }}</flux:text>
                        <flux:text>{{ ucfirst($this->viewedInvoice->status) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Date') }}</flux:text>
                        <flux:text>{{ $this->viewedInvoice->created_at->format('Y-m-d H:i') }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Total') }}</flux:text>
                        <flux:text>{{ number_format($this->viewedInvoice->total, 2) }}</flux:text>
                    </div>
                </div>

                <flux:table class="mt-6">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Service') }}</flux:table.column>
                        <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                        <flux:table.column>{{ __('Price') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->viewedInvoice->items as $item)
                            <flux:table.row wire:key="invoice-item-{{ $item->id }}">
                                <flux:table.cell>{{ $item->service_name }}</flux:table.cell>
                                <flux:table.cell>{{ $item->doctor_name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($item->price, 2) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @elseif ($this->viewingType === 'lab' && $this->viewedLabInvoice)
            <div class="print:p-0">
                <flux:heading level="2">{{ __('Lab Invoice :number', ['number' => $this->viewedLabInvoice->invoice_number]) }}</flux:heading>

                <div class="mt-6 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Patient') }}</flux:text>
                        <flux:text>{{ $this->viewedLabInvoice->patient->name }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Status') }}</flux:text>
                        <flux:text>{{ ucfirst($this->viewedLabInvoice->status) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Date') }}</flux:text>
                        <flux:text>{{ $this->viewedLabInvoice->created_at->format('Y-m-d H:i') }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Total') }}</flux:text>
                        <flux:text>{{ number_format($this->viewedLabInvoice->total, 2) }}</flux:text>
                    </div>
                </div>

                @if ((float) $this->viewedLabInvoice->discount_percentage > 0)
                    <div class="mt-4 text-sm">
                        <flux:text class="text-zinc-500">{{ __('Subtotal') }}: {{ number_format($this->viewedLabInvoice->subtotal, 2) }}</flux:text>
                        <flux:text class="text-zinc-500">{{ __('Discount') }} ({{ number_format($this->viewedLabInvoice->discount_percentage, 2) }}%): -{{ number_format($this->viewedLabInvoice->discount_amount, 2) }}</flux:text>
                    </div>
                @endif

                <flux:table class="mt-6">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Test') }}</flux:table.column>
                        <flux:table.column>{{ __('Code') }}</flux:table.column>
                        <flux:table.column>{{ __('Price') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->viewedLabInvoice->items as $item)
                            <flux:table.row wire:key="lab-invoice-item-{{ $item->id }}">
                                <flux:table.cell>{{ $item->test_name }}</flux:table.cell>
                                <flux:table.cell>{{ $item->test_code }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($item->price, 2) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif

        <div class="mt-6 flex justify-end gap-3 print:hidden">
            <flux:button type="button" variant="outline" icon="printer" x-on:click="window.print()">
                {{ __('Print') }}
            </flux:button>
            <flux:button type="button" variant="ghost" wire:click="closeViewModal">
                {{ __('Close') }}
            </flux:button>
        </div>
    </flux:modal>
</div>
