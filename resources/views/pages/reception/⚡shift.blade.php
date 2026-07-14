<?php

use App\Actions\CreatePrintJob;
use App\Models\AdminNotification;
use App\Models\Expense;
use App\Models\Shift;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Shift')] class extends Component
{
    #[Validate]
    public string $openingBalance = '';

    #[Validate]
    public string $closingBalance = '';

    #[Validate]
    public string $expenseName = '';

    #[Validate]
    public string $expenseAmount = '';

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

        if ($shift->opening_balance === 0.0) {
            AdminNotification::create([
                'user_id' => auth()->id(),
                'type' => 'shift_opened_without_balance',
                'title' => __('Shift opened without opening balance'),
                'message' => __(
                    'Receptionist :name opened shift #:shift without adding an opening balance.',
                    [
                        'name' => auth()->user()?->name ?? __('Unknown'),
                        'shift' => $shift->id,
                    ]
                ),
                'actionable_url' => route('reception.shift'),
                'metadata' => [
                    'shift_id' => $shift->id,
                ],
            ]);
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

        if ($shift->totalExpenses() === 0.0) {
            AdminNotification::create([
                'user_id' => auth()->id(),
                'type' => 'shift_closed_without_expenses',
                'title' => __('Shift closed without expenses'),
                'message' => __(
                    'Receptionist :name closed shift #:shift without recording any expenses.',
                    [
                        'name' => auth()->user()?->name ?? __('Unknown'),
                        'shift' => $shift->id,
                    ]
                ),
                'actionable_url' => route('management.shift-history'),
                'metadata' => [
                    'shift_id' => $shift->id,
                ],
            ]);
        }

        if ($shift->doctorPayouts()->count() === 0) {
            AdminNotification::create([
                'user_id' => auth()->id(),
                'type' => 'shift_closed_without_doctor_payouts',
                'title' => __('Shift closed without doctor share payments'),
                'message' => __(
                    'Receptionist :name closed shift #:shift without recording any doctor share payments.',
                    [
                        'name' => auth()->user()?->name ?? __('Unknown'),
                        'shift' => $shift->id,
                    ]
                ),
                'actionable_url' => route('payout.daily'),
                'metadata' => [
                    'shift_id' => $shift->id,
                ],
            ]);
        }

        app(CreatePrintJob::class)->createForShift($shift);

        $this->reset('closingBalance');

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
        ]);

        $this->reset(['expenseName', 'expenseAmount']);

        Flux::toast(variant: 'success', text: __('Expense added.'));
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
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Shift') }}</flux:heading>
        </div>

        @if ($this->activeShift)
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

                <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Opening Balance') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->activeShift->opening_balance, 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Walk-in Sales') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->activeShift->totalWalkInSales(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Lab Sales') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->activeShift->totalLabSales(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Procedure Payments') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->activeShift->totalProcedureSales(), 2) }}</flux:text>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-4 border-t border-zinc-200 pt-6 dark:border-zinc-700 sm:grid-cols-4">
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Total Sales') }}</flux:text>
                        <flux:text class="font-semibold">{{ number_format($this->activeShift->totalSales(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Daily Payouts') }}</flux:text>
                        <flux:text class="font-semibold text-red-600">-{{ number_format($this->activeShift->totalDailyPayouts(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Expenses') }}</flux:text>
                        <flux:text class="font-semibold text-red-600">-{{ number_format($this->activeShift->totalExpenses(), 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500">{{ __('Expected Cash') }}</flux:text>
                        <flux:heading level="3">{{ number_format($this->activeShift->expectedCash(), 2) }}</flux:heading>
                    </div>
                </div>
            </flux:card>

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
</div>
