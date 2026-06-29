<?php

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\ProcedurePayment;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shift History')] class extends Component
{
    public ?int $selectedShiftId = null;

    public bool $showShiftModal = false;

    /**
     * Get a paginated list of closed shifts with their operators.
     */
    #[Computed]
    public function closedShifts(): LengthAwarePaginator
    {
        return Shift::with('user')
            ->where('status', 'closed')
            ->latest('closed_at')
            ->paginate(10);
    }

    /**
     * Get the shift currently selected for detail viewing.
     */
    #[Computed]
    public function selectedShift(): ?Shift
    {
        if ($this->selectedShiftId === null) {
            return null;
        }

        return Shift::with('user')->find($this->selectedShiftId);
    }

    /**
     * Get the walk-in invoices for the selected shift.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function walkInInvoices(): Collection
    {
        $shift = $this->selectedShift;

        if ($shift === null) {
            return new Collection;
        }

        return Invoice::with(['patient', 'items.queueToken'])
            ->where('shift_id', $shift->id)
            ->latest()
            ->get();
    }

    /**
     * Get the lab invoices for the selected shift.
     *
     * @return Collection<int, LabInvoice>
     */
    #[Computed]
    public function labInvoices(): Collection
    {
        $shift = $this->selectedShift;

        if ($shift === null) {
            return new Collection;
        }

        return LabInvoice::with(['patient', 'items'])
            ->where('shift_id', $shift->id)
            ->latest()
            ->get();
    }

    /**
     * Get the procedure payments for the selected shift.
     *
     * @return Collection<int, ProcedurePayment>
     */
    #[Computed]
    public function procedurePayments(): Collection
    {
        $shift = $this->selectedShift;

        if ($shift === null) {
            return new Collection;
        }

        return ProcedurePayment::with(['procedure.patient', 'procedure.doctor'])
            ->where('shift_id', $shift->id)
            ->latest()
            ->get();
    }

    /**
     * Get the expenses for the selected shift.
     *
     * @return Collection<int, Expense>
     */
    #[Computed]
    public function expenses(): Collection
    {
        $shift = $this->selectedShift;

        if ($shift === null) {
            return new Collection;
        }

        return Expense::with('user')
            ->where('shift_id', $shift->id)
            ->latest()
            ->get();
    }

    /**
     * Open the detail modal for the selected shift.
     */
    public function viewShift(int $id): void
    {
        $this->selectedShiftId = $id;
        $this->showShiftModal = true;
    }

    /**
     * Close the detail modal and reset its state.
     */
    public function closeShiftModal(): void
    {
        $this->showShiftModal = false;
        $this->selectedShiftId = null;
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Shift History') }}</flux:heading>
        </div>

        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('Closed Shifts') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Opened') }}</flux:table.column>
                    <flux:table.column>{{ __('Closed') }}</flux:table.column>
                    <flux:table.column>{{ __('Operator') }}</flux:table.column>
                    <flux:table.column>{{ __('Opening') }}</flux:table.column>
                    <flux:table.column>{{ __('Sales') }}</flux:table.column>
                    <flux:table.column>{{ __('Expenses') }}</flux:table.column>
                    <flux:table.column>{{ __('Expected Cash') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->closedShifts as $shift)
                        <flux:table.row wire:key="shift-{{ $shift->id }}">
                            <flux:table.cell>{{ $shift->opened_at->format('Y-m-d H:i') }}</flux:table.cell>
                            <flux:table.cell>{{ $shift->closed_at?->format('Y-m-d H:i') ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $shift->user->name }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($shift->opening_balance, 2) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($shift->totalSales(), 2) }}</flux:table.cell>
                            <flux:table.cell class="text-red-600">-{{ number_format($shift->totalExpenses(), 2) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($shift->expectedCash(), 2) }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="eye"
                                    wire:click="viewShift({{ $shift->id }})"
                                >
                                    {{ __('View') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8" class="text-center text-zinc-500">
                                {{ __('No closed shifts found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->closedShifts->links() }}
            </div>
        </flux:card>
    </div>

    <flux:modal wire:model="showShiftModal" class="w-full max-w-4xl">
        @if ($this->selectedShift)
            <div class="flex flex-col gap-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <flux:heading level="2">
                        {{ __('Shift :opened — :operator', ['opened' => $this->selectedShift->opened_at->format('Y-m-d H:i'), 'operator' => $this->selectedShift->user->name]) }}
                    </flux:heading>
                    <flux:badge size="sm" color="zinc">{{ __('Closed') }}</flux:badge>
                </div>

                <div class="grid grid-cols-1 gap-4 border-b border-zinc-200 pb-6 dark:border-zinc-700 sm:grid-cols-4">
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Opening Balance') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->selectedShift->opening_balance, 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Closing Balance') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->selectedShift->closing_balance, 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Total Sales') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->selectedShift->totalSales(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Expected Cash') }}</flux:text>
                        <flux:heading level="3">{{ number_format($this->selectedShift->expectedCash(), 2) }}</flux:heading>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 border-b border-zinc-200 pb-6 dark:border-zinc-700 sm:grid-cols-3">
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Walk-in Sales') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->selectedShift->totalWalkInSales(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Lab Sales') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->selectedShift->totalLabSales(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Procedure Payments') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->selectedShift->totalProcedureSales(), 2) }}</flux:text>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 border-b border-zinc-200 pb-6 dark:border-zinc-700 sm:grid-cols-2">
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Expenses') }}</flux:text>
                        <flux:text class="font-semibold text-red-600">-{{ number_format($this->selectedShift->totalExpenses(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Daily Payouts') }}</flux:text>
                        <flux:text class="font-semibold text-red-600">-{{ number_format($this->selectedShift->totalDailyPayouts(), 2) }}</flux:text>
                    </div>
                </div>

                <div>
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading level="3">{{ __('Walk-in Invoices') }}</flux:heading>
                        <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($this->walkInInvoices->sum('total'), 2) }}</flux:text>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                            <flux:table.column>{{ __('Patient') }}</flux:table.column>
                            <flux:table.column>{{ __('Total') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->walkInInvoices as $invoice)
                                <flux:table.row wire:key="walkin-invoice-{{ $invoice->id }}">
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
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                        {{ __('No walk-in invoices found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>

                <div>
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading level="3">{{ __('Lab Invoices') }}</flux:heading>
                        <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($this->labInvoices->sum('total'), 2) }}</flux:text>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                            <flux:table.column>{{ __('Patient') }}</flux:table.column>
                            <flux:table.column>{{ __('Total') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
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
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                        {{ __('No lab invoices found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>

                <div>
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading level="3">{{ __('Procedure Payments') }}</flux:heading>
                        <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($this->procedurePayments->sum('amount'), 2) }}</flux:text>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Procedure') }}</flux:table.column>
                            <flux:table.column>{{ __('Patient') }}</flux:table.column>
                            <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                            <flux:table.column>{{ __('Amount') }}</flux:table.column>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->procedurePayments as $payment)
                                <flux:table.row wire:key="procedure-payment-{{ $payment->id }}">
                                    <flux:table.cell>{{ $payment->procedure->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $payment->procedure->patient->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $payment->procedure->doctor?->name ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ number_format($payment->amount, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $payment->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                        {{ __('No procedure payments found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>

                <div>
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading level="3">{{ __('Expenses') }}</flux:heading>
                        <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($this->expenses->sum('amount'), 2) }}</flux:text>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Amount') }}</flux:table.column>
                            <flux:table.column>{{ __('Recorded By') }}</flux:table.column>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->expenses as $expense)
                                <flux:table.row wire:key="expense-{{ $expense->id }}">
                                    <flux:table.cell>{{ $expense->name }}</flux:table.cell>
                                    <flux:table.cell class="text-red-600">-{{ number_format($expense->amount, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $expense->user->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $expense->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                        {{ __('No expenses found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>

                <div class="flex justify-end">
                    <flux:button type="button" variant="ghost" wire:click="closeShiftModal">
                        {{ __('Close') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
