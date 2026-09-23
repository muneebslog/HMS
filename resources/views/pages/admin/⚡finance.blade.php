<?php

use App\Enums\FinanceExpenseCategory;
use App\Enums\FinanceShiftPeriod;
use App\Models\FinanceCashEntry;
use App\Models\FinanceExpense;
use App\Services\PageAccessService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Finance')] class extends Component
{
    use WithPagination;

    public string $activeTab = 'cash';

    public int $month;

    public int $year;

    public bool $showCashModal = false;

    public ?int $editingCashId = null;

    public string $cashEntryDate = '';

    public string $cashPeriod = '';

    public string $cashAmountCollected = '';

    public string $cashAmountShort = '0';

    public string $cashNotes = '';

    public bool $showExpenseModal = false;

    public ?int $editingExpenseId = null;

    public string $expenseName = '';

    public string $expenseCategory = '';

    public string $expenseAmount = '';

    public string $expenseDate = '';

    public string $expenseNotes = '';

    public function mount(): void
    {
        $user = auth()->user();

        abort_unless(
            $user !== null && app(PageAccessService::class)->canAccess($user, 'admin.finance'),
            403
        );

        $this->month = now()->month;
        $this->year = now()->year;
        $this->cashEntryDate = now()->toDateString();
        $this->expenseDate = now()->toDateString();
        $this->cashPeriod = FinanceShiftPeriod::Morning->value;
        $this->expenseCategory = FinanceExpenseCategory::Salary->value;
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['cash', 'expenses'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->resetPage('cashPage');
        $this->resetPage('expensePage');
    }

    public function previousMonth(): void
    {
        $date = Carbon::createFromDate($this->year, $this->month, 1)->subMonth();
        $this->month = $date->month;
        $this->year = $date->year;
        $this->resetPage('cashPage');
        $this->resetPage('expensePage');
        unset($this->cashEntries, $this->financeExpenses, $this->cashSummary, $this->monthLabel);
    }

    public function nextMonth(): void
    {
        $date = Carbon::createFromDate($this->year, $this->month, 1)->addMonth();
        $this->month = $date->month;
        $this->year = $date->year;
        $this->resetPage('cashPage');
        $this->resetPage('expensePage');
        unset($this->cashEntries, $this->financeExpenses, $this->cashSummary, $this->monthLabel);
    }

    /**
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, FinanceCashEntry>
     */
    #[Computed]
    public function cashEntries()
    {
        return FinanceCashEntry::query()
            ->with('user')
            ->whereYear('entry_date', $this->year)
            ->whereMonth('entry_date', $this->month)
            ->orderByDesc('entry_date')
            ->orderBy('period')
            ->paginate(15, pageName: 'cashPage');
    }

    /**
     * @return array{collected: float, short: float, net: float}
     */
    #[Computed]
    public function cashSummary(): array
    {
        $totals = FinanceCashEntry::query()
            ->whereYear('entry_date', $this->year)
            ->whereMonth('entry_date', $this->month)
            ->selectRaw('COALESCE(SUM(amount_collected), 0) as collected, COALESCE(SUM(amount_short), 0) as short')
            ->first();

        $collected = (float) ($totals->collected ?? 0);
        $short = (float) ($totals->short ?? 0);

        return [
            'collected' => $collected,
            'short' => $short,
            'net' => $collected - $short,
        ];
    }

    /**
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, FinanceExpense>
     */
    #[Computed]
    public function financeExpenses()
    {
        return FinanceExpense::query()
            ->with('user')
            ->whereYear('expense_date', $this->year)
            ->whereMonth('expense_date', $this->month)
            ->orderByDesc('expense_date')
            ->orderBy('name')
            ->paginate(15, pageName: 'expensePage');
    }

    #[Computed]
    public function monthLabel(): string
    {
        return Carbon::createFromDate($this->year, $this->month, 1)->format('F Y');
    }

    public function openCashModal(): void
    {
        $this->resetCashForm();
        $this->cashEntryDate = Carbon::createFromDate(
            $this->year,
            $this->month,
            min(now()->day, Carbon::createFromDate($this->year, $this->month, 1)->daysInMonth)
        )->toDateString();
        $this->showCashModal = true;
    }

    public function editCashEntry(int $id): void
    {
        $entry = FinanceCashEntry::query()->findOrFail($id);

        $this->editingCashId = $entry->id;
        $this->cashEntryDate = $entry->entry_date->toDateString();
        $this->cashPeriod = $entry->period->value;
        $this->cashAmountCollected = (string) $entry->amount_collected;
        $this->cashAmountShort = (string) $entry->amount_short;
        $this->cashNotes = $entry->notes ?? '';
        $this->resetValidation();
        $this->showCashModal = true;
    }

    public function saveCashEntry(): void
    {
        $validated = $this->validate([
            'cashEntryDate' => ['required', 'date'],
            'cashPeriod' => ['required', Rule::enum(FinanceShiftPeriod::class)],
            'cashAmountCollected' => ['required', 'numeric', 'min:0'],
            'cashAmountShort' => ['required', 'numeric', 'min:0'],
            'cashNotes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'cashEntryDate' => __('date'),
            'cashPeriod' => __('period'),
            'cashAmountCollected' => __('amount collected'),
            'cashAmountShort' => __('amount short'),
            'cashNotes' => __('notes'),
        ]);

        $attributes = [
            'entry_date' => $validated['cashEntryDate'],
            'period' => $validated['cashPeriod'],
            'amount_collected' => (float) $validated['cashAmountCollected'],
            'amount_short' => (float) $validated['cashAmountShort'],
            'notes' => filled($validated['cashNotes'] ?? null) ? $validated['cashNotes'] : null,
        ];

        $duplicate = FinanceCashEntry::query()
            ->whereDate('entry_date', $attributes['entry_date'])
            ->where('period', $attributes['period'])
            ->when($this->editingCashId !== null, fn ($query) => $query->whereKeyNot($this->editingCashId))
            ->exists();

        if ($duplicate) {
            $this->addError('cashPeriod', __('An entry for this date and period already exists.'));

            return;
        }

        if ($this->editingCashId !== null) {
            FinanceCashEntry::query()->findOrFail($this->editingCashId)->update($attributes);
            Flux::toast(variant: 'success', text: __('Cash entry updated.'));
        } else {
            FinanceCashEntry::query()->create([
                ...$attributes,
                'user_id' => auth()->id(),
            ]);
            Flux::toast(variant: 'success', text: __('Cash entry added.'));
        }

        $this->showCashModal = false;
        $this->resetCashForm();
        unset($this->cashEntries, $this->cashSummary);
    }

    public function deleteCashEntry(int $id): void
    {
        FinanceCashEntry::query()->whereKey($id)->delete();
        unset($this->cashEntries, $this->cashSummary);

        Flux::toast(variant: 'success', text: __('Cash entry removed.'));
    }

    public function openExpenseModal(): void
    {
        $this->resetExpenseForm();
        $this->expenseDate = Carbon::createFromDate(
            $this->year,
            $this->month,
            min(now()->day, Carbon::createFromDate($this->year, $this->month, 1)->daysInMonth)
        )->toDateString();
        $this->showExpenseModal = true;
    }

    public function editExpense(int $id): void
    {
        $expense = FinanceExpense::query()->findOrFail($id);

        $this->editingExpenseId = $expense->id;
        $this->expenseName = $expense->name;
        $this->expenseCategory = $expense->category->value;
        $this->expenseAmount = (string) $expense->amount;
        $this->expenseDate = $expense->expense_date->toDateString();
        $this->expenseNotes = $expense->notes ?? '';
        $this->resetValidation();
        $this->showExpenseModal = true;
    }

    public function saveExpense(): void
    {
        $validated = $this->validate([
            'expenseName' => ['required', 'string', 'max:255'],
            'expenseCategory' => ['required', Rule::enum(FinanceExpenseCategory::class)],
            'expenseAmount' => ['required', 'numeric', 'min:0.01'],
            'expenseDate' => ['required', 'date'],
            'expenseNotes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'expenseName' => __('name'),
            'expenseCategory' => __('category'),
            'expenseAmount' => __('amount'),
            'expenseDate' => __('date'),
            'expenseNotes' => __('notes'),
        ]);

        $attributes = [
            'name' => $validated['expenseName'],
            'category' => $validated['expenseCategory'],
            'amount' => (float) $validated['expenseAmount'],
            'expense_date' => $validated['expenseDate'],
            'notes' => filled($validated['expenseNotes'] ?? null) ? $validated['expenseNotes'] : null,
        ];

        if ($this->editingExpenseId !== null) {
            FinanceExpense::query()->findOrFail($this->editingExpenseId)->update($attributes);
            Flux::toast(variant: 'success', text: __('Expense updated.'));
        } else {
            FinanceExpense::query()->create([
                ...$attributes,
                'user_id' => auth()->id(),
            ]);
            Flux::toast(variant: 'success', text: __('Expense added.'));
        }

        $this->showExpenseModal = false;
        $this->resetExpenseForm();
        unset($this->financeExpenses);
    }

    public function deleteExpense(int $id): void
    {
        FinanceExpense::query()->whereKey($id)->delete();
        unset($this->financeExpenses);

        Flux::toast(variant: 'success', text: __('Expense removed.'));
    }

    public function resetCashForm(): void
    {
        $this->editingCashId = null;
        $this->cashEntryDate = now()->toDateString();
        $this->cashPeriod = FinanceShiftPeriod::Morning->value;
        $this->cashAmountCollected = '';
        $this->cashAmountShort = '0';
        $this->cashNotes = '';
        $this->resetValidation();
    }

    public function resetExpenseForm(): void
    {
        $this->editingExpenseId = null;
        $this->expenseName = '';
        $this->expenseCategory = FinanceExpenseCategory::Salary->value;
        $this->expenseAmount = '';
        $this->expenseDate = now()->toDateString();
        $this->expenseNotes = '';
        $this->resetValidation();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading level="1">{{ __('Finance') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">
                {{ __('Record shift cash collections and hospital expenses for :month. Separate from reception shift expenses.', ['month' => $this->monthLabel]) }}
            </flux:text>
        </div>

        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="previousMonth" />
            <flux:text class="min-w-36 text-center font-semibold">{{ $this->monthLabel }}</flux:text>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="nextMonth" />
        </div>
    </div>

    <div class="flex gap-6 border-b border-zinc-200 dark:border-zinc-700">
        <button
            type="button"
            wire:click="setTab('cash')"
            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'cash' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
        >
            {{ __('Cash Collections') }}
        </button>
        <button
            type="button"
            wire:click="setTab('expenses')"
            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'expenses' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
        >
            {{ __('Expenses') }}
        </button>
    </div>

    @if ($activeTab === 'cash')
        @php($summary = $this->cashSummary)

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Collected') }}</flux:text>
                <flux:heading level="2" class="mt-1 text-green-700 dark:text-green-400">
                    {{ number_format($summary['collected'], 2) }}
                </flux:heading>
            </flux:card>
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Shortage') }}</flux:text>
                <flux:heading level="2" class="mt-1 text-red-700 dark:text-red-400">
                    {{ number_format($summary['short'], 2) }}
                </flux:heading>
            </flux:card>
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Net') }}</flux:text>
                <flux:heading level="2" class="mt-1 {{ $summary['net'] >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                    {{ number_format($summary['net'], 2) }}
                </flux:heading>
            </flux:card>
        </div>

        <flux:card>
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:text>{{ __('Cash collected per morning, evening, or night period.') }}</flux:text>
                <flux:button variant="primary" icon="plus" wire:click="openCashModal">
                    {{ __('Add cash entry') }}
                </flux:button>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Period') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Collected') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Shortage') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Net') }}</flux:table.column>
                    <flux:table.column>{{ __('Notes') }}</flux:table.column>
                    <flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->cashEntries as $entry)
                        <flux:table.row wire:key="cash-entry-{{ $entry->id }}">
                            <flux:table.cell class="font-medium">{{ $entry->entry_date->format('M j, Y') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ $entry->period->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-right text-green-700 dark:text-green-400">
                                {{ number_format($entry->amount_collected, 2) }}
                            </flux:table.cell>
                            <flux:table.cell class="text-right text-red-700 dark:text-red-400">
                                {{ number_format($entry->amount_short, 2) }}
                            </flux:table.cell>
                            <flux:table.cell class="text-right font-medium">
                                {{ number_format($entry->netAmount(), 2) }}
                            </flux:table.cell>
                            <flux:table.cell class="max-w-48 truncate text-zinc-500">
                                {{ $entry->notes ?: '—' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="editCashEntry({{ $entry->id }})">
                                        {{ __('Edit') }}
                                    </flux:button>
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="deleteCashEntry({{ $entry->id }})"
                                        wire:confirm="{{ __('Remove this cash entry?') }}"
                                    >
                                        {{ __('Delete') }}
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="py-8 text-center text-zinc-500">
                                {{ __('No cash entries for this month.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->cashEntries->links() }}
            </div>
        </flux:card>
    @else
        <flux:card>
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:text>{{ __('Salary and other hospital expenses (not reception shift expenses).') }}</flux:text>
                <flux:button variant="primary" icon="plus" wire:click="openExpenseModal">
                    {{ __('Add expense') }}
                </flux:button>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Category') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Amount') }}</flux:table.column>
                    <flux:table.column>{{ __('Notes') }}</flux:table.column>
                    <flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->financeExpenses as $expense)
                        <flux:table.row wire:key="finance-expense-{{ $expense->id }}">
                            <flux:table.cell class="font-medium">{{ $expense->expense_date->format('M j, Y') }}</flux:table.cell>
                            <flux:table.cell>{{ $expense->name }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$expense->category === \App\Enums\FinanceExpenseCategory::Salary ? 'blue' : 'zinc'">
                                    {{ $expense->category->label() }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-right font-medium">
                                {{ number_format($expense->amount, 2) }}
                            </flux:table.cell>
                            <flux:table.cell class="max-w-48 truncate text-zinc-500">
                                {{ $expense->notes ?: '—' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="editExpense({{ $expense->id }})">
                                        {{ __('Edit') }}
                                    </flux:button>
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="deleteExpense({{ $expense->id }})"
                                        wire:confirm="{{ __('Remove this expense?') }}"
                                    >
                                        {{ __('Delete') }}
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="py-8 text-center text-zinc-500">
                                {{ __('No expenses for this month.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->financeExpenses->links() }}
            </div>
        </flux:card>
    @endif

    <flux:modal wire:model="showCashModal" class="max-w-md">
        <form wire:submit="saveCashEntry" class="space-y-4">
            <flux:heading size="lg">
                {{ $editingCashId ? __('Edit cash entry') : __('Add cash entry') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Date') }}</flux:label>
                <flux:input type="date" wire:model="cashEntryDate" />
                <flux:error name="cashEntryDate" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Period') }}</flux:label>
                <flux:select wire:model="cashPeriod">
                    @foreach (\App\Enums\FinanceShiftPeriod::cases() as $period)
                        <option value="{{ $period->value }}">{{ $period->label() }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="cashPeriod" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Amount collected') }}</flux:label>
                <flux:input type="number" step="0.01" min="0" wire:model="cashAmountCollected" />
                <flux:error name="cashAmountCollected" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Amount short') }}</flux:label>
                <flux:input type="number" step="0.01" min="0" wire:model="cashAmountShort" />
                <flux:description>{{ __('Money less than expected for this period.') }}</flux:description>
                <flux:error name="cashAmountShort" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="cashNotes" rows="2" />
                <flux:error name="cashNotes" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showCashModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showExpenseModal" class="max-w-md">
        <form wire:submit="saveExpense" class="space-y-4">
            <flux:heading size="lg">
                {{ $editingExpenseId ? __('Edit expense') : __('Add expense') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="expenseName" placeholder="{{ __('e.g. Staff Salaries') }}" />
                <flux:error name="expenseName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Category') }}</flux:label>
                <flux:select wire:model="expenseCategory">
                    @foreach (\App\Enums\FinanceExpenseCategory::cases() as $category)
                        <option value="{{ $category->value }}">{{ $category->label() }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="expenseCategory" />
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
                <flux:textarea wire:model="expenseNotes" rows="2" />
                <flux:error name="expenseNotes" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showExpenseModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
