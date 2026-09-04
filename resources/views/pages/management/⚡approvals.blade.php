<?php

use App\Actions\ApproveExpense;
use App\Actions\ApproveReturn;
use App\Actions\RejectExpense;
use App\Actions\RejectReturn;
use App\Enums\ApprovalStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\ProcedurePayment;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Approvals')] class extends Component
{
    public string $activeTab = 'returns';

    public string $rejectNote = '';

    /**
     * Switch between pending returns and expenses.
     */
    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['returns', 'expenses'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->rejectNote = '';
    }

    /**
     * Approve a pending return.
     */
    public function approveReturn(int $id, string $type): void
    {
        $document = $this->findReturnDocument($id, $type);

        if ($document === null) {
            Flux::toast(variant: 'danger', text: __('Return not found.'));

            return;
        }

        try {
            app(ApproveReturn::class)->handle(auth()->user(), $document);
        } catch (InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->refreshLists();

        Flux::toast(variant: 'success', text: __('Return approved.'));
    }

    /**
     * Reject a pending return and restore the sale.
     */
    public function rejectReturn(int $id, string $type): void
    {
        $document = $this->findReturnDocument($id, $type);

        if ($document === null) {
            Flux::toast(variant: 'danger', text: __('Return not found.'));

            return;
        }

        try {
            app(RejectReturn::class)->handle(auth()->user(), $document, $this->rejectNote !== '' ? $this->rejectNote : null);
        } catch (InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->rejectNote = '';
        $this->refreshLists();

        Flux::toast(variant: 'success', text: __('Return rejected. Sale restored to cash.'));
    }

    /**
     * Approve a pending expense.
     */
    public function approveExpense(int $id): void
    {
        $expense = Expense::find($id);

        if ($expense === null) {
            Flux::toast(variant: 'danger', text: __('Expense not found.'));

            return;
        }

        try {
            app(ApproveExpense::class)->handle(auth()->user(), $expense);
        } catch (InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->refreshLists();

        Flux::toast(variant: 'success', text: __('Expense approved.'));
    }

    /**
     * Reject a pending expense so it no longer counts toward cash.
     */
    public function rejectExpense(int $id): void
    {
        $expense = Expense::find($id);

        if ($expense === null) {
            Flux::toast(variant: 'danger', text: __('Expense not found.'));

            return;
        }

        try {
            app(RejectExpense::class)->handle(auth()->user(), $expense, $this->rejectNote !== '' ? $this->rejectNote : null);
        } catch (InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->rejectNote = '';
        $this->refreshLists();

        Flux::toast(variant: 'success', text: __('Expense rejected. Removed from cash.'));
    }

    /**
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function pendingWalkInReturns(): Collection
    {
        return Invoice::with(['patient', 'returnRequester', 'shift.user'])
            ->where('status', 'returned')
            ->where('return_approval_status', ApprovalStatus::Pending)
            ->latest('updated_at')
            ->get();
    }

    /**
     * @return Collection<int, LabInvoice>
     */
    #[Computed]
    public function pendingLabReturns(): Collection
    {
        return LabInvoice::with(['patient', 'returnRequester', 'shift.user'])
            ->where('status', 'returned')
            ->where('return_approval_status', ApprovalStatus::Pending)
            ->latest('updated_at')
            ->get();
    }

    /**
     * @return Collection<int, ProcedurePayment>
     */
    #[Computed]
    public function pendingProcedureReturns(): Collection
    {
        return ProcedurePayment::with(['procedure.patient', 'returnRequester', 'shift.user'])
            ->whereNotNull('returned_at')
            ->where('return_approval_status', ApprovalStatus::Pending)
            ->latest('returned_at')
            ->get();
    }

    /**
     * @return Collection<int, Expense>
     */
    #[Computed]
    public function pendingExpenses(): Collection
    {
        return Expense::with(['user', 'shift.user'])
            ->where('approval_status', ApprovalStatus::Pending)
            ->latest()
            ->get();
    }

    /**
     * Find a returnable document by type.
     */
    private function findReturnDocument(int $id, string $type): Invoice|LabInvoice|ProcedurePayment|null
    {
        return match ($type) {
            'walkin' => Invoice::find($id),
            'lab' => LabInvoice::find($id),
            'procedure' => ProcedurePayment::find($id),
            default => null,
        };
    }

    /**
     * Clear computed pending lists after an action.
     */
    private function refreshLists(): void
    {
        unset($this->pendingWalkInReturns);
        unset($this->pendingLabReturns);
        unset($this->pendingProcedureReturns);
        unset($this->pendingExpenses);
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Approvals') }}</flux:heading>
        </div>

        <div class="flex gap-6 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
            <button
                type="button"
                wire:click="setActiveTab('returns')"
                class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium whitespace-nowrap transition-colors {{ $activeTab === 'returns' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
            >
                {{ __('Pending returns') }}
                <span class="ms-1 text-xs text-zinc-400">({{ $this->pendingWalkInReturns->count() + $this->pendingLabReturns->count() + $this->pendingProcedureReturns->count() }})</span>
            </button>
            <button
                type="button"
                wire:click="setActiveTab('expenses')"
                class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium whitespace-nowrap transition-colors {{ $activeTab === 'expenses' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
            >
                {{ __('Pending expenses') }}
                <span class="ms-1 text-xs text-zinc-400">({{ $this->pendingExpenses->count() }})</span>
            </button>
        </div>

        <flux:field>
            <flux:label>{{ __('Reject note (optional)') }}</flux:label>
            <flux:input wire:model="rejectNote" type="text" placeholder="{{ __('Reason for rejection') }}" />
        </flux:field>

        @if ($activeTab === 'returns')
            <flux:card>
                <flux:heading level="2">{{ __('Walk-in returns') }}</flux:heading>

                <flux:table class="mt-4">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                        <flux:table.column>{{ __('Patient') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Shift') }}</flux:table.column>
                        <flux:table.column>{{ __('Requested by') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->pendingWalkInReturns as $invoice)
                            <flux:table.row wire:key="pending-walkin-{{ $invoice->id }}">
                                <flux:table.cell>{{ $invoice->invoice_number }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice->patient?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($invoice->total, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice->shift?->user?->name ?? '-' }} · {{ $invoice->shift?->opened_at?->format('Y-m-d') }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice->returnRequester?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <flux:button size="sm" variant="primary" wire:click="approveReturn({{ $invoice->id }}, 'walkin')">{{ __('Approve') }}</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="rejectReturn({{ $invoice->id }}, 'walkin')" wire:confirm="{{ __('Reject this return and restore the sale?') }}">{{ __('Reject') }}</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('No pending walk-in returns.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card>
                <flux:heading level="2">{{ __('Lab returns') }}</flux:heading>

                <flux:table class="mt-4">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                        <flux:table.column>{{ __('Patient') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Shift') }}</flux:table.column>
                        <flux:table.column>{{ __('Requested by') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->pendingLabReturns as $invoice)
                            <flux:table.row wire:key="pending-lab-{{ $invoice->id }}">
                                <flux:table.cell>{{ $invoice->invoice_number }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice->patient?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($invoice->total, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice->shift?->user?->name ?? '-' }} · {{ $invoice->shift?->opened_at?->format('Y-m-d') }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice->returnRequester?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <flux:button size="sm" variant="primary" wire:click="approveReturn({{ $invoice->id }}, 'lab')">{{ __('Approve') }}</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="rejectReturn({{ $invoice->id }}, 'lab')" wire:confirm="{{ __('Reject this return and restore the sale?') }}">{{ __('Reject') }}</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('No pending lab returns.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card>
                <flux:heading level="2">{{ __('Procedure payment returns') }}</flux:heading>

                <flux:table class="mt-4">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Procedure') }}</flux:table.column>
                        <flux:table.column>{{ __('Patient') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Shift') }}</flux:table.column>
                        <flux:table.column>{{ __('Requested by') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->pendingProcedureReturns as $payment)
                            <flux:table.row wire:key="pending-procedure-{{ $payment->id }}">
                                <flux:table.cell>{{ $payment->procedure?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ $payment->procedure?->patient?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($payment->amount, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $payment->shift?->user?->name ?? '-' }} · {{ $payment->shift?->opened_at?->format('Y-m-d') }}</flux:table.cell>
                                <flux:table.cell>{{ $payment->returnRequester?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <flux:button size="sm" variant="primary" wire:click="approveReturn({{ $payment->id }}, 'procedure')">{{ __('Approve') }}</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="rejectReturn({{ $payment->id }}, 'procedure')" wire:confirm="{{ __('Reject this return and restore the sale?') }}">{{ __('Reject') }}</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('No pending procedure returns.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @else
            <flux:card>
                <flux:heading level="2">{{ __('Pending expenses') }}</flux:heading>

                <flux:table class="mt-4">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Shift') }}</flux:table.column>
                        <flux:table.column>{{ __('Logged by') }}</flux:table.column>
                        <flux:table.column>{{ __('Added') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->pendingExpenses as $expense)
                            <flux:table.row wire:key="pending-expense-{{ $expense->id }}">
                                <flux:table.cell>{{ $expense->name }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($expense->amount, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $expense->shift?->user?->name ?? '-' }} · {{ $expense->shift?->opened_at?->format('Y-m-d') }}</flux:table.cell>
                                <flux:table.cell>{{ $expense->user?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ $expense->created_at->format('Y-m-d H:i') }}</flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <flux:button size="sm" variant="primary" wire:click="approveExpense({{ $expense->id }})">{{ __('Approve') }}</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="rejectExpense({{ $expense->id }})" wire:confirm="{{ __('Reject this expense and remove it from cash?') }}">{{ __('Reject') }}</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('No pending expenses.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif
    </div>
</div>
