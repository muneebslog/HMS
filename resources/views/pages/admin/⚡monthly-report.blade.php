<?php

use App\Models\MonthlyExpense;
use App\Services\MonthlyReportService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Monthly Report')] class extends Component
{
    public int $month;

    public int $year;

    public string $expenseName = '';

    public string $expenseAmount = '';

    public string $expenseDate = '';

    public string $expenseNotes = '';

    public bool $showExpenseModal = false;

    /**
     * Restrict the page to admin users and default to the current month.
     */
    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }

        $this->month = now()->month;
        $this->year = now()->year;
        $this->expenseDate = now()->toDateString();
    }

    /**
     * Get the validation rules for monthly expense forms.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'expenseName' => ['required', 'string', 'max:255'],
            'expenseAmount' => ['required', 'numeric', 'min:0.01'],
            'expenseDate' => ['required', 'date'],
            'expenseNotes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Move to the previous calendar month.
     */
    public function previousMonth(): void
    {
        $date = Carbon::createFromDate($this->year, $this->month, 1)->subMonth();
        $this->month = $date->month;
        $this->year = $date->year;
        unset($this->report);
    }

    /**
     * Move to the next calendar month.
     */
    public function nextMonth(): void
    {
        $date = Carbon::createFromDate($this->year, $this->month, 1)->addMonth();
        $this->month = $date->month;
        $this->year = $date->year;
        unset($this->report);
    }

    /**
     * Open the add monthly expense modal.
     */
    public function openExpenseModal(): void
    {
        $this->resetValidation();
        $this->expenseName = '';
        $this->expenseAmount = '';
        $this->expenseDate = Carbon::createFromDate($this->year, $this->month, min(now()->day, Carbon::createFromDate($this->year, $this->month, 1)->daysInMonth))->toDateString();
        $this->expenseNotes = '';
        $this->showExpenseModal = true;
    }

    /**
     * Add a monthly overhead expense that does not affect shift balances.
     */
    public function addExpense(): void
    {
        $validated = $this->validate();

        MonthlyExpense::create([
            'user_id' => auth()->id(),
            'name' => $validated['expenseName'],
            'amount' => (float) $validated['expenseAmount'],
            'expense_date' => $validated['expenseDate'],
            'notes' => $validated['expenseNotes'] ?: null,
        ]);

        $this->showExpenseModal = false;
        $this->reset(['expenseName', 'expenseAmount', 'expenseNotes']);
        unset($this->report);

        Flux::toast(variant: 'success', text: __('Monthly expense added. Shift balances are unchanged.'));
    }

    /**
     * Delete a monthly overhead expense.
     */
    public function deleteExpense(int $id): void
    {
        MonthlyExpense::query()->whereKey($id)->delete();
        unset($this->report);

        Flux::toast(variant: 'success', text: __('Monthly expense removed.'));
    }

    /**
     * Get the financial report for the selected month.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function report(): array
    {
        return app(MonthlyReportService::class)->forMonth(
            Carbon::createFromDate($this->year, $this->month, 1)
        );
    }

    /**
     * Get a display label for the selected month.
     */
    #[Computed]
    public function monthLabel(): string
    {
        return Carbon::createFromDate($this->year, $this->month, 1)->format('F Y');
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Monthly Report') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    {{ __('Hospital cash flow for :month. Overhead expenses here do not affect shift balances.', ['month' => $this->monthLabel]) }}
                </flux:text>
            </div>

            <div class="flex items-center gap-2">
                <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="previousMonth" />
                <flux:text class="min-w-36 text-center font-semibold">{{ $this->monthLabel }}</flux:text>
                <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="nextMonth" />
            </div>
        </div>

        @php($report = $this->report)

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Total Income') }}</flux:text>
                <flux:heading level="2" class="mt-1 text-green-700 dark:text-green-400">
                    {{ number_format($report['total_income'], 2) }}
                </flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-500">
                    {{ __('Receipts + Labs + Procedures') }}
                </flux:text>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Total Outflow') }}</flux:text>
                <flux:heading level="2" class="mt-1 text-red-700 dark:text-red-400">
                    {{ number_format($report['total_outflow'], 2) }}
                </flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-500">
                    {{ __('Expenses + Doctor payouts') }}
                </flux:text>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Hospital Net') }}</flux:text>
                <flux:heading level="2" class="mt-1 {{ $report['hospital_net'] >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                    {{ number_format($report['hospital_net'], 2) }}
                </flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-500">
                    {{ __('Income minus outflow') }}
                </flux:text>
            </flux:card>

            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Hospital Share of Receipts') }}</flux:text>
                <flux:heading level="2" class="mt-1">
                    {{ number_format($report['hospital_share_of_receipts'], 2) }}
                </flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-500">
                    {{ __('Receipts minus accrued doctor shares') }}
                </flux:text>
            </flux:card>
        </div>

        <flux:card>
            <flux:heading level="2" class="mb-4">{{ __('Cash Flow') }}</flux:heading>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="space-y-3">
                    <flux:heading level="3">{{ __('Money In') }}</flux:heading>
                    <div class="flex items-center justify-between border-b border-zinc-200 py-2 dark:border-zinc-700">
                        <flux:text>{{ __('Receipts (OPD / Walk-in)') }}</flux:text>
                        <flux:text class="font-semibold text-green-700 dark:text-green-400">+{{ number_format($report['receipts_total'], 2) }}</flux:text>
                    </div>
                    <div class="flex items-center justify-between border-b border-zinc-200 py-2 dark:border-zinc-700">
                        <flux:text>{{ __('Labs') }}</flux:text>
                        <flux:text class="font-semibold text-green-700 dark:text-green-400">+{{ number_format($report['lab_total'], 2) }}</flux:text>
                    </div>
                    <div class="flex items-center justify-between border-b border-zinc-200 py-2 dark:border-zinc-700">
                        <flux:text>{{ __('Procedure Payments') }}</flux:text>
                        <flux:text class="font-semibold text-green-700 dark:text-green-400">+{{ number_format($report['procedure_total'], 2) }}</flux:text>
                    </div>
                    <div class="flex items-center justify-between pt-2">
                        <flux:text class="font-semibold">{{ __('Total Income') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($report['total_income'], 2) }}</flux:text>
                    </div>
                </div>

                <div class="space-y-3">
                    <flux:heading level="3">{{ __('Money Out') }}</flux:heading>
                    <div class="flex items-center justify-between border-b border-zinc-200 py-2 dark:border-zinc-700">
                        <flux:text>{{ __('Shift Expenses') }}</flux:text>
                        <flux:text class="font-semibold text-red-600">-{{ number_format($report['shift_expenses_total'], 2) }}</flux:text>
                    </div>
                    <div class="flex items-center justify-between border-b border-zinc-200 py-2 dark:border-zinc-700">
                        <flux:text>{{ __('Monthly Overhead') }}</flux:text>
                        <flux:text class="font-semibold text-red-600">-{{ number_format($report['monthly_expenses_total'], 2) }}</flux:text>
                    </div>
                    <div class="flex items-center justify-between border-b border-zinc-200 py-2 dark:border-zinc-700">
                        <flux:text>{{ __('Doctor Payouts (Paid)') }}</flux:text>
                        <flux:text class="font-semibold text-red-600">-{{ number_format($report['doctor_payouts_total'], 2) }}</flux:text>
                    </div>
                    <div class="flex items-center justify-between pt-2">
                        <flux:text class="font-semibold">{{ __('Total Outflow') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($report['total_outflow'], 2) }}</flux:text>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex items-center justify-between rounded-lg bg-zinc-100 px-4 py-3 dark:bg-zinc-800">
                <flux:text class="font-semibold">{{ __('Hospital Made This Month') }}</flux:text>
                <flux:heading level="3" class="{{ $report['hospital_net'] >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                    {{ number_format($report['hospital_net'], 2) }}
                </flux:heading>
            </div>
        </flux:card>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <flux:card>
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <flux:heading level="2">{{ __('Receipts') }}</flux:heading>
                    <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($report['receipts_total'], 2) }}</flux:text>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                        <flux:table.column>{{ __('Patient') }}</flux:table.column>
                        <flux:table.column>{{ __('Total') }}</flux:table.column>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($report['receipts'] as $invoice)
                            <flux:table.row wire:key="receipt-{{ $invoice->id }}">
                                <flux:table.cell>{{ $invoice->invoice_number }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice->patient->name }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($invoice->total, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice->created_at->format('Y-m-d') }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center text-zinc-500">{{ __('No receipts this month.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card>
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <flux:heading level="2">{{ __('Labs') }}</flux:heading>
                    <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($report['lab_total'], 2) }}</flux:text>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Invoice #') }}</flux:table.column>
                        <flux:table.column>{{ __('Patient') }}</flux:table.column>
                        <flux:table.column>{{ __('Total') }}</flux:table.column>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($report['labs'] as $invoice)
                            <flux:table.row wire:key="lab-{{ $invoice->id }}">
                                <flux:table.cell>{{ $invoice->invoice_number }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice->patient->name }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($invoice->total, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice->created_at->format('Y-m-d') }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center text-zinc-500">{{ __('No lab invoices this month.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        </div>

        <flux:card>
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Procedure Payments') }}</flux:heading>
                <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($report['procedure_total'], 2) }}</flux:text>
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
                    @forelse ($report['procedure_payments'] as $payment)
                        <flux:table.row wire:key="procedure-{{ $payment->id }}">
                            <flux:table.cell>{{ $payment->procedure->name }}</flux:table.cell>
                            <flux:table.cell>{{ $payment->procedure->patient->name }}</flux:table.cell>
                            <flux:table.cell>{{ $payment->procedure->doctor?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($payment->amount, 2) }}</flux:table.cell>
                            <flux:table.cell>{{ $payment->created_at->format('Y-m-d') }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('No procedure payments this month.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <flux:card>
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Doctor Shares (Accrued)') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">{{ __('Calculated from receipts this month') }}</flux:text>
                    </div>
                    <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($report['doctor_shares_accrued_total'], 2) }}</flux:text>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                        <flux:table.column>{{ __('Services') }}</flux:table.column>
                        <flux:table.column>{{ __('Share') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($report['doctor_shares'] as $row)
                            <flux:table.row wire:key="share-{{ $row['doctor']->id }}">
                                <flux:table.cell>{{ $row['doctor']->name }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($row['total_amount'], 2) }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($row['share_amount'], 2) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="3" class="text-center text-zinc-500">{{ __('No doctor shares this month.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card>
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Doctor Payouts (Paid)') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">{{ __('Cash paid out during this month') }}</flux:text>
                    </div>
                    <flux:text class="font-semibold">{{ __('Total') }}: {{ number_format($report['doctor_payouts_total'], 2) }}</flux:text>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                        <flux:table.column>{{ __('Share Paid') }}</flux:table.column>
                        <flux:table.column>{{ __('Paid At') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($report['doctor_payouts'] as $payout)
                            <flux:table.row wire:key="payout-{{ $payout->id }}">
                                <flux:table.cell>{{ $payout->doctor->name }}</flux:table.cell>
                                <flux:table.cell class="text-red-600">-{{ number_format($payout->share_amount, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $payout->paid_at?->format('Y-m-d') ?? '-' }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="3" class="text-center text-zinc-500">{{ __('No doctor payouts this month.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <flux:card>
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Shift Expenses') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">{{ __('Logged during reception shifts') }}</flux:text>
                    </div>
                    <flux:text class="font-semibold text-red-600">-{{ number_format($report['shift_expenses_total'], 2) }}</flux:text>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('By') }}</flux:table.column>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($report['shift_expenses'] as $expense)
                            <flux:table.row wire:key="shift-expense-{{ $expense->id }}">
                                <flux:table.cell>{{ $expense->name }}</flux:table.cell>
                                <flux:table.cell class="text-red-600">-{{ number_format($expense->amount, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $expense->user->name }}</flux:table.cell>
                                <flux:table.cell>{{ $expense->created_at->format('Y-m-d') }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center text-zinc-500">{{ __('No shift expenses this month.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card>
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading level="2">{{ __('Monthly Overhead') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">{{ __('Electricity, rent, etc. — does not affect shift balance') }}</flux:text>
                    </div>
                    <div class="flex items-center gap-3">
                        <flux:text class="font-semibold text-red-600">-{{ number_format($report['monthly_expenses_total'], 2) }}</flux:text>
                        <flux:button size="sm" variant="primary" icon="plus" wire:click="openExpenseModal">
                            {{ __('Add Expense') }}
                        </flux:button>
                    </div>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($report['monthly_expenses'] as $expense)
                            <flux:table.row wire:key="monthly-expense-{{ $expense->id }}">
                                <flux:table.cell>
                                    <div>{{ $expense->name }}</div>
                                    @if ($expense->notes)
                                        <flux:text class="text-sm text-zinc-500">{{ $expense->notes }}</flux:text>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-red-600">-{{ number_format($expense->amount, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $expense->expense_date->format('Y-m-d') }}</flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="deleteExpense({{ $expense->id }})"
                                        wire:confirm="{{ __('Remove this monthly expense?') }}"
                                    />
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center text-zinc-500">{{ __('No monthly overhead expenses yet.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        </div>
    </div>

    <flux:modal wire:model="showExpenseModal" class="md:w-96">
        <form wire:submit="addExpense" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Add Monthly Expense') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Overhead costs such as electricity. These are not deducted from any shift.') }}</flux:text>
            </div>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="expenseName" placeholder="{{ __('Electricity') }}" />
                <flux:error name="expenseName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Amount') }}</flux:label>
                <flux:input type="number" step="0.01" min="0.01" wire:model="expenseAmount" />
                <flux:error name="expenseAmount" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Date') }}</flux:label>
                <flux:input type="date" wire:model="expenseDate" />
                <flux:error name="expenseDate" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="expenseNotes" rows="3" />
                <flux:error name="expenseNotes" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showExpenseModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save Expense') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
