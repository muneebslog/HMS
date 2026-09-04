<?php

use App\Actions\CreatePrintJob;
use App\Actions\MarkInvoiceReturn;
use App\Enums\ApprovalStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\ProcedurePayment;
use App\Models\Shift;
use App\Services\NotificationService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Shift')] class extends Component
{
    public string $activeTab = 'overview';

    #[Validate]
    public string $openingBalance = '';

    #[Validate]
    public string $closingBalance = '';

    #[Validate]
    public string $expenseName = '';

    #[Validate]
    public string $expenseAmount = '';

    public ?int $viewingInvoiceId = null;

    public ?string $viewingType = null;

    public bool $showViewModal = false;

    /**
     * Get the validation rules for the shift form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'openingBalance' => ['required', 'numeric', 'min:0'],
            'closingBalance' => ['required', 'numeric', 'min:0'],
            'expenseName' => ['required', 'string', 'max:255'],
            'expenseAmount' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Switch the active tab on the open shift view.
     */
    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['overview', 'invoices', 'expenses'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    /**
     * Open a new shift for the authenticated user.
     */
    public function openShift(): void
    {
        $validated = $this->validate([
            'openingBalance' => $this->rules()['openingBalance'],
        ]);

        $existingOpenShift = Shift::current();

        if ($existingOpenShift !== null) {
            Flux::toast(variant: 'danger', text: __('You already have an open shift.'));

            return;
        }

        $shift = Shift::create([
            'user_id' => auth()->id(),
            'opened_at' => now(),
            'opening_balance' => (float) $validated['openingBalance'],
            'status' => 'open',
        ]);

        if ($shift->opening_balance === 0.0 && auth()->user() !== null) {
            app(NotificationService::class)->notifyShiftOpenedWithoutBalance(auth()->user(), $shift);
        }

        $this->reset('openingBalance');

        Flux::toast(variant: 'success', text: __('Shift opened.'));
    }

    /**
     * Close the currently open shift.
     */
    public function closeShift(): void
    {
        $validated = $this->validate([
            'closingBalance' => $this->rules()['closingBalance'],
        ]);

        $shift = Shift::current();

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('No open shift found.'));

            return;
        }

        $shift->update([
            'closed_at' => now(),
            'closing_balance' => (float) $validated['closingBalance'],
            'status' => 'closed',
        ]);

        if ($shift->totalExpenses() === 0.0 && auth()->user() !== null) {
            app(NotificationService::class)->notifyShiftClosedWithoutExpenses(auth()->user(), $shift);
        }

        if ($shift->doctorPayouts()->count() === 0 && auth()->user() !== null) {
            app(NotificationService::class)->notifyShiftClosedWithoutDoctorPayouts(auth()->user(), $shift);
        }

        app(CreatePrintJob::class)->createForShift($shift);

        $this->reset('closingBalance');
        $this->activeTab = 'overview';

        Flux::toast(variant: 'success', text: __('Shift closed. Total sales: :total', ['total' => number_format($shift->totalSales(), 2)]));
    }

    /**
     * Add an expense to the currently open shift.
     */
    public function addExpense(): void
    {
        $validated = $this->validate([
            'expenseName' => $this->rules()['expenseName'],
            'expenseAmount' => $this->rules()['expenseAmount'],
        ]);

        $shift = Shift::current();

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('No open shift found.'));

            return;
        }

        Expense::create([
            'shift_id' => $shift->id,
            'user_id' => auth()->id(),
            'name' => $validated['expenseName'],
            'amount' => (float) $validated['expenseAmount'],
            'approval_status' => ApprovalStatus::Pending,
        ]);

        $this->reset(['expenseName', 'expenseAmount']);
        unset($this->shiftExpenses);
        unset($this->activeShift);

        Flux::toast(variant: 'success', text: __('Expense added. Pending management approval.'));
    }

    /**
     * Mark a walk-in invoice, lab invoice, or procedure payment as returned.
     */
    public function markReturn(int $id, string $type): void
    {
        $document = match ($type) {
            'walkin' => Invoice::find($id),
            'lab' => LabInvoice::find($id),
            'procedure' => ProcedurePayment::find($id),
            default => null,
        };

        if ($document === null) {
            Flux::toast(variant: 'danger', text: __('Document not found.'));

            return;
        }

        $shift = Shift::current();

        if ($shift === null || (int) $document->shift_id !== (int) $shift->id) {
            Flux::toast(variant: 'danger', text: __('Returns can only be marked for the current open shift.'));

            return;
        }

        try {
            app(MarkInvoiceReturn::class)->handle(auth()->user(), $document);
        } catch (InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        unset($this->invoices);
        unset($this->labInvoices);
        unset($this->procedurePayments);
        unset($this->activeShift);

        Flux::toast(variant: 'success', text: __('Marked as return. Pending management approval.'));
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
     * Queue a print job for the selected invoice.
     */
    public function printInvoice(int $id, string $type): void
    {
        $invoice = match ($type) {
            'walkin' => Invoice::find($id),
            'lab' => LabInvoice::find($id),
            default => null,
        };

        if ($invoice === null) {
            Flux::toast(variant: 'danger', text: __('Invoice not found.'));

            return;
        }

        app(CreatePrintJob::class)->create($invoice);

        Flux::toast(variant: 'success', text: __('Print job queued for :number.', ['number' => $invoice->invoice_number]));
    }

    /**
     * Get the currently open shift for the user.
     */
    #[Computed]
    public function activeShift(): ?Shift
    {
        return Shift::current();
    }

    /**
     * Get the expenses for the active shift.
     *
     * @return Collection<int, Expense>
     */
    #[Computed]
    public function shiftExpenses(): Collection
    {
        $shift = $this->activeShift;

        if ($shift === null) {
            return new Collection;
        }

        return $shift->expenses()->latest()->get();
    }

    /**
     * Get the walk-in invoices for the current shift.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        $shift = $this->activeShift;

        if ($shift === null) {
            return new Collection;
        }

        return Invoice::with(['patient', 'items.queueToken'])
            ->where('shift_id', $shift->id)
            ->latest()
            ->get();
    }

    /**
     * Get the lab invoices for the current shift.
     *
     * @return Collection<int, LabInvoice>
     */
    #[Computed]
    public function labInvoices(): Collection
    {
        $shift = $this->activeShift;

        if ($shift === null) {
            return new Collection;
        }

        return LabInvoice::with(['patient', 'items'])
            ->where('shift_id', $shift->id)
            ->latest()
            ->get();
    }

    /**
     * Get the procedure payments for the current shift.
     *
     * @return Collection<int, ProcedurePayment>
     */
    #[Computed]
    public function procedurePayments(): Collection
    {
        $shift = $this->activeShift;

        if ($shift === null) {
            return new Collection;
        }

        return ProcedurePayment::with(['procedure.patient', 'procedure.doctor'])
            ->where('shift_id', $shift->id)
            ->latest()
            ->get();
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

        return Invoice::with(['patient', 'items.queueToken'])->find($this->viewingInvoiceId);
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
            <flux:heading level="1">{{ __('Shift') }}</flux:heading>
        </div>

        @if ($this->activeShift)
            <div class="flex gap-6 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
                <button
                    type="button"
                    wire:click="setActiveTab('overview')"
                    class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium whitespace-nowrap transition-colors {{ $activeTab === 'overview' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                >
                    {{ __('Overview') }}
                </button>
                <button
                    type="button"
                    wire:click="setActiveTab('invoices')"
                    class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium whitespace-nowrap transition-colors {{ $activeTab === 'invoices' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                >
                    {{ __('Invoices') }}
                </button>
                <button
                    type="button"
                    wire:click="setActiveTab('expenses')"
                    class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium whitespace-nowrap transition-colors {{ $activeTab === 'expenses' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                >
                    {{ __('Expenses') }}
                </button>
            </div>

            @if ($activeTab === 'overview')
                <flux:card>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <flux:heading level="2">{{ __('Open Shift') }}</flux:heading>
                            <flux:text class="text-zinc-500">
                                {{ __('Opened at') }}: {{ $this->activeShift->opened_at->format('Y-m-d H:i') }}
                            </flux:text>
                        </div>
                        <flux:badge size="sm" color="green">{{ __('Open') }}</flux:badge>
                    </div>

                    <div class="mt-6 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50 sm:p-6">
                        <x-shift-cash-breakdown :shift="$this->activeShift" />
                    </div>
                </flux:card>

                <flux:card>
                    <flux:heading level="2">{{ __('Close Shift') }}</flux:heading>

                    <form wire:submit="closeShift" class="mt-6 space-y-6">
                        <flux:field>
                            <flux:label>{{ __('Closing Balance') }}</flux:label>
                            <flux:input
                                wire:model="closingBalance"
                                type="number"
                                step="0.01"
                                min="0"
                                required
                                placeholder="0.00"
                            />
                            <flux:error name="closingBalance" />
                        </flux:field>

                        <div class="flex justify-end">
                            <flux:button type="submit" variant="primary" icon="lock-closed">
                                {{ __('Close Shift') }}
                            </flux:button>
                        </div>
                    </form>
                </flux:card>
            @elseif ($activeTab === 'invoices')
                <flux:card>
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading level="2">{{ __('Walk-in Invoices') }}</flux:heading>
                        <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($this->invoices->sum('total'), 2) }}</flux:text>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                            <flux:table.column>{{ __('Patient') }}</flux:table.column>
                            <flux:table.column>{{ __('Total') }}</flux:table.column>
                            <flux:table.column>{{ __('Mode') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->invoices as $invoice)
                                <flux:table.row wire:key="shift-invoice-{{ $invoice->id }}">
                                    <flux:table.cell>{{ $invoice->invoice_number }}</flux:table.cell>
                                    <flux:table.cell>
                                        <div>{{ $invoice->patient->name }}</div>
                                        <div class="text-xs text-zinc-500">{{ $invoice->patient->mrn ?? __('No MRN') }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ number_format($invoice->total, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $invoice->payment_mode?->label() ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($invoice->status === 'returned')
                                            @if ($invoice->return_approval_status === ApprovalStatus::Pending)
                                                <flux:badge size="sm" color="amber">{{ __('Return pending') }}</flux:badge>
                                            @elseif ($invoice->return_approval_status === ApprovalStatus::Approved)
                                                <flux:badge size="sm" color="zinc">{{ __('Return approved') }}</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="red">{{ __('Returned') }}</flux:badge>
                                            @endif
                                        @elseif ($invoice->return_approval_status === ApprovalStatus::Rejected)
                                            <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge>
                                            <flux:badge size="sm" color="red" class="ms-1">{{ __('Return rejected') }}</flux:badge>
                                        @elseif ($invoice->status === 'paid')
                                            <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge>
                                        @elseif ($invoice->status === 'cancelled')
                                            <flux:badge size="sm" color="red">{{ __('Cancelled') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $invoice->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="eye" wire:click="viewInvoice({{ $invoice->id }}, 'walkin')" />
                                        <flux:button size="sm" variant="ghost" icon="printer" wire:click="printInvoice({{ $invoice->id }}, 'walkin')" />
                                        @if ($invoice->status === 'paid')
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                icon="arrow-uturn-left"
                                                wire:click="markReturn({{ $invoice->id }}, 'walkin')"
                                                wire:confirm="{{ __('Mark this invoice as a return? Cash will update immediately.') }}"
                                            />
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="7" class="text-center text-zinc-500">
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
                        <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($this->labInvoices->sum('total'), 2) }}</flux:text>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                            <flux:table.column>{{ __('Patient') }}</flux:table.column>
                            <flux:table.column>{{ __('Total') }}</flux:table.column>
                            <flux:table.column>{{ __('Mode') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->labInvoices as $invoice)
                                <flux:table.row wire:key="shift-lab-invoice-{{ $invoice->id }}">
                                    <flux:table.cell>{{ $invoice->invoice_number }}</flux:table.cell>
                                    <flux:table.cell>
                                        <div>{{ $invoice->patient->name }}</div>
                                        <div class="text-xs text-zinc-500">{{ $invoice->patient->mrn ?? __('No MRN') }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ number_format($invoice->total, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $invoice->payment_mode?->label() ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($invoice->status === 'returned')
                                            @if ($invoice->return_approval_status === ApprovalStatus::Pending)
                                                <flux:badge size="sm" color="amber">{{ __('Return pending') }}</flux:badge>
                                            @elseif ($invoice->return_approval_status === ApprovalStatus::Approved)
                                                <flux:badge size="sm" color="zinc">{{ __('Return approved') }}</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="red">{{ __('Returned') }}</flux:badge>
                                            @endif
                                        @elseif ($invoice->return_approval_status === ApprovalStatus::Rejected)
                                            <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge>
                                            <flux:badge size="sm" color="red" class="ms-1">{{ __('Return rejected') }}</flux:badge>
                                        @elseif ($invoice->status === 'paid')
                                            <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge>
                                        @elseif ($invoice->status === 'cancelled')
                                            <flux:badge size="sm" color="red">{{ __('Cancelled') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $invoice->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="eye" wire:click="viewInvoice({{ $invoice->id }}, 'lab')" />
                                        <flux:button size="sm" variant="ghost" icon="printer" wire:click="printInvoice({{ $invoice->id }}, 'lab')" />
                                        @if ($invoice->status === 'paid')
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                icon="arrow-uturn-left"
                                                wire:click="markReturn({{ $invoice->id }}, 'lab')"
                                                wire:confirm="{{ __('Mark this invoice as a return? Cash will update immediately.') }}"
                                            />
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="7" class="text-center text-zinc-500">
                                        {{ __('No lab invoices found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </flux:card>

                <flux:card>
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <flux:heading level="2">{{ __('Procedure Payments') }}</flux:heading>
                        <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($this->procedurePayments->sum('amount'), 2) }}</flux:text>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Procedure') }}</flux:table.column>
                            <flux:table.column>{{ __('Patient') }}</flux:table.column>
                            <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                            <flux:table.column>{{ __('Amount') }}</flux:table.column>
                            <flux:table.column>{{ __('Mode') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('Date') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->procedurePayments as $payment)
                                <flux:table.row wire:key="shift-procedure-payment-{{ $payment->id }}">
                                    <flux:table.cell>{{ $payment->procedure->name }}</flux:table.cell>
                                    <flux:table.cell>
                                        <div>{{ $payment->procedure->patient->name }}</div>
                                        <div class="text-xs text-zinc-500">{{ $payment->procedure->patient->mrn ?? __('No MRN') }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $payment->procedure->doctor?->name ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ number_format($payment->amount, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $payment->mode?->label() ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($payment->isDiscarded())
                                            <flux:badge size="sm" color="red">{{ __('Discarded') }}</flux:badge>
                                        @elseif ($payment->isReturned())
                                            @if ($payment->return_approval_status === ApprovalStatus::Pending)
                                                <flux:badge size="sm" color="amber">{{ __('Return pending') }}</flux:badge>
                                            @elseif ($payment->return_approval_status === ApprovalStatus::Approved)
                                                <flux:badge size="sm" color="zinc">{{ __('Return approved') }}</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="red">{{ __('Returned') }}</flux:badge>
                                            @endif
                                        @elseif ($payment->return_approval_status === ApprovalStatus::Rejected)
                                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                            <flux:badge size="sm" color="red" class="ms-1">{{ __('Return rejected') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $payment->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        @if (! $payment->isDiscarded() && ! $payment->isReturned())
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                icon="arrow-uturn-left"
                                                wire:click="markReturn({{ $payment->id }}, 'procedure')"
                                                wire:confirm="{{ __('Mark this payment as a return? Cash will update immediately.') }}"
                                            />
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="8" class="text-center text-zinc-500">
                                        {{ __('No procedure payments found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </flux:card>
            @elseif ($activeTab === 'expenses')
                <flux:card>
                    <flux:heading level="2">{{ __('Expenses') }}</flux:heading>

                    <form wire:submit="addExpense" class="mt-6 space-y-6">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Expense Name') }}</flux:label>
                                <flux:input
                                    wire:model="expenseName"
                                    type="text"
                                    required
                                    placeholder="{{ __('e.g. Stationery') }}"
                                />
                                <flux:error name="expenseName" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Amount') }}</flux:label>
                                <flux:input
                                    wire:model="expenseAmount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    required
                                    placeholder="0.00"
                                />
                                <flux:error name="expenseAmount" />
                            </flux:field>
                        </div>

                        <div class="flex justify-end">
                            <flux:button type="submit" variant="primary" icon="plus">
                                {{ __('Add Expense') }}
                            </flux:button>
                        </div>
                    </form>

                    @if ($this->shiftExpenses->isNotEmpty())
                        <div class="mt-6 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                <thead class="bg-zinc-50 dark:bg-zinc-800">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                            {{ __('Name') }}
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">
                                            {{ __('Amount') }}
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                            {{ __('Approval') }}
                                        </th>
                                        <th scope="col" class="hidden px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 sm:table-cell">
                                            {{ __('Added') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                                    @foreach ($this->shiftExpenses as $expense)
                                        <tr wire:key="expense-{{ $expense->id }}">
                                            <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                                {{ $expense->name }}
                                            </td>
                                            <td class="px-4 py-3 text-right text-sm text-zinc-700 dark:text-zinc-300">
                                                {{ number_format($expense->amount, 2) }}
                                            </td>
                                            <td class="px-4 py-3 text-sm">
                                                @if ($expense->approval_status === ApprovalStatus::Pending)
                                                    <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                                                @elseif ($expense->approval_status === ApprovalStatus::Approved)
                                                    <flux:badge size="sm" color="green">{{ __('Approved') }}</flux:badge>
                                                @else
                                                    <flux:badge size="sm" color="red">{{ __('Rejected') }}</flux:badge>
                                                @endif
                                            </td>
                                            <td class="hidden px-4 py-3 text-right text-sm text-zinc-500 sm:table-cell">
                                                {{ $expense->created_at->format('Y-m-d H:i') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-zinc-50 dark:bg-zinc-800">
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                            {{ __('Total Expenses') }}
                                        </td>
                                        <td class="px-4 py-3 text-right text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                            {{ number_format($this->activeShift->totalExpenses(), 2) }}
                                        </td>
                                        <td></td>
                                        <td class="hidden sm:table-cell"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @else
                        <flux:text class="mt-6 text-zinc-500">
                            {{ __('No expenses logged yet.') }}
                        </flux:text>
                    @endif
                </flux:card>
            @endif
        @else
            <flux:card>
                <flux:heading level="2">{{ __('Open Shift') }}</flux:heading>

                <form wire:submit="openShift" class="mt-6 space-y-6">
                    <flux:field>
                        <flux:label>{{ __('Opening Balance') }}</flux:label>
                        <flux:input
                            wire:model="openingBalance"
                            type="number"
                            step="0.01"
                            min="0"
                            required
                            placeholder="0.00"
                        />
                        <flux:error name="openingBalance" />
                    </flux:field>

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" icon="lock-open">
                            {{ __('Open Shift') }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        @endif
    </div>

    <flux:modal wire:model="showViewModal" class="w-full max-w-2xl">
        @if ($this->viewingType === 'walkin' && $this->viewedInvoice)
            <div class="print:p-0">
                <flux:heading level="2">{{ __('Invoice :number', ['number' => $this->viewedInvoice->invoice_number]) }}</flux:heading>

                <div class="mt-6 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Patient') }}</flux:text>
                        <flux:text>{{ $this->viewedInvoice->patient->name }}</flux:text>
                        <flux:text class="text-zinc-500">{{ $this->viewedInvoice->patient->mrn ?? __('No MRN') }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Status') }}</flux:text>
                        <flux:text>{{ ucfirst($this->viewedInvoice->status) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Payment mode') }}</flux:text>
                        <flux:text>{{ $this->viewedInvoice->payment_mode?->label() ?? '-' }}</flux:text>
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
                        <flux:table.column>{{ __('Token') }}</flux:table.column>
                        <flux:table.column>{{ __('Price') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->viewedInvoice->items as $item)
                            <flux:table.row wire:key="shift-invoice-item-{{ $item->id }}">
                                <flux:table.cell>{{ $item->service_name }}</flux:table.cell>
                                <flux:table.cell>{{ $item->doctor_name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ $item->queueToken?->token_number ?? '-' }}</flux:table.cell>
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
                        <flux:text class="text-zinc-500">{{ $this->viewedLabInvoice->patient->mrn ?? __('No MRN') }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Status') }}</flux:text>
                        <flux:text>{{ ucfirst($this->viewedLabInvoice->status) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Payment mode') }}</flux:text>
                        <flux:text>{{ $this->viewedLabInvoice->payment_mode?->label() ?? '-' }}</flux:text>
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

                <flux:table class="mt-6">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Test') }}</flux:table.column>
                        <flux:table.column>{{ __('Code') }}</flux:table.column>
                        <flux:table.column>{{ __('Price') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->viewedLabInvoice->items as $item)
                            <flux:table.row wire:key="shift-lab-invoice-item-{{ $item->id }}">
                                <flux:table.cell>{{ $item->test_name }}</flux:table.cell>
                                <flux:table.cell>{{ $item->test_code ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($item->price, 2) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif

        <div class="mt-6 flex justify-end gap-3 print:hidden">
            @if ($this->viewingInvoiceId && $this->viewingType && $this->viewingType !== 'procedure')
                <flux:button type="button" variant="outline" icon="printer" wire:click="printInvoice({{ $this->viewingInvoiceId }}, '{{ $this->viewingType }}')">
                    {{ __('Print') }}
                </flux:button>
            @endif
            <flux:button type="button" variant="ghost" wire:click="closeViewModal">
                {{ __('Close') }}
            </flux:button>
        </div>
    </flux:modal>
</div>
