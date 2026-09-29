<?php

use App\Actions\ApproveExpense;
use App\Actions\ApprovePendingShiftItems;
use App\Actions\ApproveReturn;
use App\Actions\RejectExpense;
use App\Actions\RejectReturn;
use App\Actions\SettleShift;
use App\Enums\ApprovalStatus;
use App\Enums\FinanceExpenseCategory;
use App\Enums\FinanceShiftPeriod;
use App\Enums\PaymentMode;
use App\Models\Expense;
use App\Models\FinanceExpense;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\ProcedurePayment;
use App\Models\Shift;
use App\Models\ShiftSettlement;
use App\Services\PageAccessService;
use Flux\Flux;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Finance')] class extends Component
{
    use WithPagination;

    public string $activeTab = 'shifts';

    public string $date = '';

    public ?int $selectedShiftId = null;

    public string $receivedAmount = '';

    public string $settlementNotes = '';

    public int $month;

    public int $year;

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

        $this->date = now()->toDateString();
        $this->month = now()->month;
        $this->year = now()->year;
        $this->expenseDate = now()->toDateString();
        $this->expenseCategory = FinanceExpenseCategory::Salary->value;
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['shifts', 'month', 'expenses'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->resetPage('expensePage');
    }

    /**
     * React to the date picker changing.
     */
    public function updatedDate(): void
    {
        if (strtotime($this->date) === false) {
            $this->date = now()->toDateString();
        }

        $this->deselectShift();
    }

    public function previousDay(): void
    {
        $this->date = Carbon::parse($this->date)->subDay()->toDateString();
        $this->deselectShift();
    }

    public function nextDay(): void
    {
        $this->date = Carbon::parse($this->date)->addDay()->toDateString();
        $this->deselectShift();
    }

    public function selectShift(int $shiftId): void
    {
        $this->selectedShiftId = $this->selectedShiftId === $shiftId ? null : $shiftId;
        $this->receivedAmount = '';
        $this->settlementNotes = '';
        $this->resetValidation();
        $this->refreshShift();
    }

    public function deselectShift(): void
    {
        $this->selectedShiftId = null;
        $this->receivedAmount = '';
        $this->settlementNotes = '';
        $this->resetValidation();
        $this->refreshShift();
    }

    /**
     * Get the shifts for the picked business date grouped by period (night, morning, evening).
     *
     * @return Collection<string, Collection<int, Shift>>
     */
    #[Computed]
    public function shiftsByPeriod(): Collection
    {
        $shifts = Shift::query()
            ->with(['user', 'settlement'])
            ->forBusinessDate(Carbon::parse($this->date))
            ->orderBy('opened_at')
            ->get();

        return collect(FinanceShiftPeriod::cases())
            ->sortBy(fn (FinanceShiftPeriod $period) => $period->sortOrder())
            ->mapWithKeys(fn (FinanceShiftPeriod $period) => [
                $period->value => $shifts->filter(fn (Shift $shift) => $shift->period() === $period)->values(),
            ]);
    }

    /**
     * Pending approval counts for every shift on the picked date.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function pendingCounts(): array
    {
        return $this->shiftsByPeriod
            ->flatten()
            ->mapWithKeys(fn (Shift $shift) => [$shift->id => $shift->settlement || $shift->isBeforeFinanceTracking() ? 0 : $shift->pendingApprovalsCount()])
            ->all();
    }

    /**
     * @return array{expected: float, received: float, difference: float, settled: int, total: int}
     */
    #[Computed]
    public function dayTotals(): array
    {
        $shifts = $this->shiftsByPeriod->flatten();
        $settlements = $shifts->pluck('settlement')->filter();

        return [
            'expected' => (float) $settlements->sum('expected_amount'),
            'received' => (float) $settlements->sum('received_amount'),
            'difference' => (float) $settlements->sum('difference'),
            'settled' => $settlements->count(),
            'total' => $shifts->reject(fn (Shift $shift) => $shift->isBeforeFinanceTracking())->count(),
        ];
    }

    #[Computed]
    public function selectedShift(): ?Shift
    {
        if ($this->selectedShiftId === null) {
            return null;
        }

        return Shift::with(['user', 'settlement.settler'])->find($this->selectedShiftId);
    }

    /**
     * Live cash summary for the selected shift.
     *
     * @return array{walkin_cash: float, lab_cash: float, procedure_cash: float, cash_sales: float, online_sales: float, payouts: float, expenses: float, expected: float}
     */
    #[Computed]
    public function shiftSummary(): array
    {
        $shift = $this->selectedShift;

        if ($shift === null) {
            return ['walkin_cash' => 0.0, 'lab_cash' => 0.0, 'procedure_cash' => 0.0, 'cash_sales' => 0.0, 'online_sales' => 0.0, 'payouts' => 0.0, 'expenses' => 0.0, 'expected' => 0.0];
        }

        return [
            'walkin_cash' => $shift->totalWalkInSales(PaymentMode::Cash),
            'lab_cash' => $shift->totalLabSales(PaymentMode::Cash),
            'procedure_cash' => $shift->totalProcedureSales(PaymentMode::Cash),
            'cash_sales' => $shift->totalCashSales(),
            'online_sales' => $shift->totalOnlineSales(),
            'payouts' => $shift->totalDailyPayouts(),
            'expenses' => $shift->totalExpenses(),
            'expected' => round($shift->expectedCash(), 2),
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Expense>
     */
    #[Computed]
    public function shiftExpenses()
    {
        return Expense::query()
            ->with(['user', 'editor', 'reviewer'])
            ->where('shift_id', $this->selectedShiftId)
            ->oldest()
            ->get();
    }

    /**
     * Returned walk-in, lab and procedure payments on the selected shift.
     *
     * @return Collection<int, array{id: int, type: string, type_label: string, reference: string, patient: string, amount: float, status: ApprovalStatus|null, requested_by: string}>
     */
    #[Computed]
    public function shiftReturns(): Collection
    {
        if ($this->selectedShiftId === null) {
            return collect();
        }

        $walkIns = Invoice::with(['patient', 'returnRequester'])
            ->where('shift_id', $this->selectedShiftId)
            ->where('status', 'returned')
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'type' => 'walkin',
                'type_label' => __('Walk-in'),
                'reference' => $invoice->invoice_number,
                'patient' => $invoice->patient?->name ?? '-',
                'amount' => (float) $invoice->total,
                'status' => $invoice->return_approval_status,
                'requested_by' => $invoice->returnRequester?->name ?? '-',
            ]);

        $labs = LabInvoice::with(['patient', 'returnRequester'])
            ->where('shift_id', $this->selectedShiftId)
            ->where('status', 'returned')
            ->get()
            ->map(fn (LabInvoice $invoice) => [
                'id' => $invoice->id,
                'type' => 'lab',
                'type_label' => __('Lab'),
                'reference' => $invoice->invoice_number,
                'patient' => $invoice->patient?->name ?? '-',
                'amount' => (float) $invoice->total,
                'status' => $invoice->return_approval_status,
                'requested_by' => $invoice->returnRequester?->name ?? '-',
            ]);

        $procedures = ProcedurePayment::with(['procedure.patient', 'returnRequester'])
            ->where('shift_id', $this->selectedShiftId)
            ->whereNotNull('returned_at')
            ->get()
            ->map(fn (ProcedurePayment $payment) => [
                'id' => $payment->id,
                'type' => 'procedure',
                'type_label' => __('Procedure'),
                'reference' => $payment->procedure?->name ?? '-',
                'patient' => $payment->procedure?->patient?->name ?? '-',
                'amount' => (float) $payment->amount,
                'status' => $payment->return_approval_status,
                'requested_by' => $payment->returnRequester?->name ?? '-',
            ]);

        return $walkIns->concat($labs)->concat($procedures)->values();
    }

    #[Computed]
    public function selectedPendingCount(): int
    {
        return $this->shiftExpenses->where('approval_status', ApprovalStatus::Pending)->count()
            + $this->shiftReturns->where('status', ApprovalStatus::Pending)->count();
    }

    public function approveExpense(int $id): void
    {
        $this->runShiftAction(fn () => app(ApproveExpense::class)->handle(auth()->user(), $this->findShiftExpense($id)), __('Expense approved.'));
    }

    public function declineExpense(int $id): void
    {
        $this->runShiftAction(fn () => app(RejectExpense::class)->handle(auth()->user(), $this->findShiftExpense($id)), __('Expense declined. Removed from cash.'));
    }

    public function approveReturn(int $id, string $type): void
    {
        $this->runShiftAction(fn () => app(ApproveReturn::class)->handle(auth()->user(), $this->findShiftReturn($id, $type)), __('Return approved.'));
    }

    public function declineReturn(int $id, string $type): void
    {
        $this->runShiftAction(fn () => app(RejectReturn::class)->handle(auth()->user(), $this->findShiftReturn($id, $type)), __('Return declined. Sale restored to cash.'));
    }

    public function approveAll(): void
    {
        $shift = $this->selectedShift;

        if ($shift === null) {
            return;
        }

        $approved = 0;

        $this->runShiftAction(function () use ($shift, &$approved): void {
            $approved = app(ApprovePendingShiftItems::class)->handle(auth()->user(), $shift);
        }, null);

        if ($approved > 0) {
            Flux::toast(variant: 'success', text: trans_choice(':count item approved.|:count items approved.', $approved, ['count' => $approved]));
        }
    }

    public function settle(): void
    {
        $shift = $this->selectedShift;

        if ($shift === null) {
            return;
        }

        $validated = $this->validate([
            'receivedAmount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'settlementNotes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'receivedAmount' => __('amount received'),
            'settlementNotes' => __('notes'),
        ]);

        try {
            $settlement = app(SettleShift::class)->handle(
                auth()->user(),
                $shift,
                (float) $validated['receivedAmount'],
                $validated['settlementNotes'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
            $this->refreshShift();

            return;
        }

        $this->receivedAmount = '';
        $this->settlementNotes = '';
        $this->refreshShift();

        Flux::toast(variant: 'success', text: __('Shift settled. Received :amount.', ['amount' => number_format($settlement->received_amount, 2)]));
    }

    /**
     * Month overview: settled shift cash per business day plus hospital expenses.
     *
     * @return array{days: Collection<int, array{date: Carbon, shifts: int, expected: float, received: float, difference: float}>, expected: float, received: float, difference: float, hospital_expenses: float, net: float}
     */
    #[Computed]
    public function monthOverview(): array
    {
        $settlements = ShiftSettlement::query()
            ->whereYear('business_date', $this->year)
            ->whereMonth('business_date', $this->month)
            ->get();

        $days = $settlements
            ->groupBy(fn (ShiftSettlement $settlement) => $settlement->business_date->toDateString())
            ->sortKeysDesc()
            ->map(fn ($group, string $date) => [
                'date' => Carbon::parse($date),
                'shifts' => $group->count(),
                'expected' => (float) $group->sum('expected_amount'),
                'received' => (float) $group->sum('received_amount'),
                'difference' => (float) $group->sum('difference'),
            ])
            ->values();

        $hospitalExpenses = (float) FinanceExpense::query()
            ->whereYear('expense_date', $this->year)
            ->whereMonth('expense_date', $this->month)
            ->sum('amount');

        $received = (float) $settlements->sum('received_amount');

        return [
            'days' => $days,
            'expected' => (float) $settlements->sum('expected_amount'),
            'received' => $received,
            'difference' => (float) $settlements->sum('difference'),
            'hospital_expenses' => $hospitalExpenses,
            'net' => $received - $hospitalExpenses,
        ];
    }

    public function previousMonth(): void
    {
        $date = Carbon::createFromDate($this->year, $this->month, 1)->subMonth();
        $this->month = $date->month;
        $this->year = $date->year;
        $this->resetPage('expensePage');
        unset($this->financeExpenses, $this->monthOverview, $this->monthLabel);
    }

    public function nextMonth(): void
    {
        $date = Carbon::createFromDate($this->year, $this->month, 1)->addMonth();
        $this->month = $date->month;
        $this->year = $date->year;
        $this->resetPage('expensePage');
        unset($this->financeExpenses, $this->monthOverview, $this->monthLabel);
    }

    public function showDay(string $date): void
    {
        $this->date = $date;
        $this->activeTab = 'shifts';
        $this->deselectShift();
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
        unset($this->financeExpenses, $this->monthOverview);
    }

    public function deleteExpense(int $id): void
    {
        FinanceExpense::query()->whereKey($id)->delete();
        unset($this->financeExpenses, $this->monthOverview);

        Flux::toast(variant: 'success', text: __('Expense removed.'));
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

    /**
     * Run an approval action against the selected shift, refusing once the shift is settled.
     */
    private function runShiftAction(callable $action, ?string $successMessage): void
    {
        if ($this->selectedShift === null) {
            return;
        }

        if ($this->selectedShift->settlement !== null) {
            Flux::toast(variant: 'danger', text: __('This shift has already been settled.'));

            return;
        }

        if ($this->selectedShift->isBeforeFinanceTracking()) {
            Flux::toast(variant: 'danger', text: __('This shift is from before finance tracking started.'));

            return;
        }

        try {
            $action();
        } catch (InvalidArgumentException|ModelNotFoundException $exception) {
            Flux::toast(variant: 'danger', text: $exception instanceof InvalidArgumentException ? $exception->getMessage() : __('Item not found on this shift.'));
            $this->refreshShift();

            return;
        }

        $this->refreshShift();

        if ($successMessage !== null) {
            Flux::toast(variant: 'success', text: $successMessage);
        }
    }

    private function findShiftExpense(int $id): Expense
    {
        return Expense::query()->where('shift_id', $this->selectedShiftId)->findOrFail($id);
    }

    private function findShiftReturn(int $id, string $type): Invoice|LabInvoice|ProcedurePayment
    {
        $model = match ($type) {
            'walkin' => Invoice::class,
            'lab' => LabInvoice::class,
            'procedure' => ProcedurePayment::class,
            default => throw new InvalidArgumentException(__('Unknown return type.')),
        };

        return $model::query()->where('shift_id', $this->selectedShiftId)->findOrFail($id);
    }

    private function refreshShift(): void
    {
        unset(
            $this->shiftsByPeriod,
            $this->pendingCounts,
            $this->dayTotals,
            $this->selectedShift,
            $this->shiftSummary,
            $this->shiftExpenses,
            $this->shiftReturns,
            $this->selectedPendingCount,
        );
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading level="1">{{ __('Finance') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">
                {{ __('Review each shift, approve its expenses and returns, then receive the cash.') }}
            </flux:text>
        </div>
    </div>

    <div class="flex gap-6 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
        @foreach (['shifts' => __('Shifts'), 'month' => __('Month'), 'expenses' => __('Hospital Expenses')] as $tab => $label)
            <button
                type="button"
                wire:click="setTab('{{ $tab }}')"
                class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium whitespace-nowrap transition-colors {{ $activeTab === $tab ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($activeTab === 'shifts')
        @php($totals = $this->dayTotals)

        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-end gap-2">
                <flux:button variant="ghost" icon="chevron-left" wire:click="previousDay" :aria-label="__('Previous day')" />
                <flux:field>
                    <flux:label>{{ __('Date') }}</flux:label>
                    <flux:input type="date" wire:model.live="date" />
                </flux:field>
                <flux:button variant="ghost" icon="chevron-right" wire:click="nextDay" :aria-label="__('Next day')" />
            </div>

            <div class="grid grid-cols-3 gap-3 text-sm">
                <div class="rounded-lg border border-zinc-200 px-4 py-2 dark:border-zinc-700">
                    <div class="text-zinc-500">{{ __('Settled') }}</div>
                    <div class="font-semibold">{{ $totals['settled'] }} / {{ $totals['total'] }}</div>
                </div>
                <div class="rounded-lg border border-zinc-200 px-4 py-2 dark:border-zinc-700">
                    <div class="text-zinc-500">{{ __('Received') }}</div>
                    <div class="font-semibold text-green-700 dark:text-green-400">{{ number_format($totals['received'], 2) }}</div>
                </div>
                <div class="rounded-lg border border-zinc-200 px-4 py-2 dark:border-zinc-700">
                    <div class="text-zinc-500">{{ __('Short / Over') }}</div>
                    <div class="font-semibold {{ $totals['difference'] < 0 ? 'text-red-700 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">
                        {{ $totals['difference'] > 0 ? '+' : '' }}{{ number_format($totals['difference'], 2) }}
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            @foreach ($this->shiftsByPeriod as $periodValue => $shifts)
                @php($period = \App\Enums\FinanceShiftPeriod::from($periodValue))
                <div class="flex flex-col gap-2" wire:key="period-{{ $periodValue }}">
                    <flux:heading size="sm" class="flex items-center gap-2">
                        <flux:icon :name="match ($period) { \App\Enums\FinanceShiftPeriod::Night => 'moon', \App\Enums\FinanceShiftPeriod::Morning => 'sun', \App\Enums\FinanceShiftPeriod::Evening => 'cloud' }" variant="mini" class="text-zinc-400" />
                        {{ $period->label() }}
                    </flux:heading>

                    @forelse ($shifts as $shift)
                        @php($pending = $this->pendingCounts[$shift->id] ?? 0)
                        <button
                            type="button"
                            wire:key="shift-card-{{ $shift->id }}"
                            wire:click="selectShift({{ $shift->id }})"
                            class="cursor-pointer rounded-xl border p-4 text-left transition-colors {{ $selectedShiftId === $shift->id ? 'border-zinc-900 bg-zinc-50 dark:border-white dark:bg-zinc-800' : 'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500' }}"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="font-medium">{{ $shift->user?->name ?? __('Unknown') }}</div>
                                    <div class="text-xs text-zinc-500">
                                        {{ $shift->opened_at->format('M j, H:i') }} → {{ $shift->closed_at?->format('M j, H:i') ?? __('now') }}
                                    </div>
                                </div>

                                @if ($shift->settlement)
                                    <flux:badge size="sm" color="green" icon="lock-closed">{{ __('Settled') }}</flux:badge>
                                @elseif ($shift->isBeforeFinanceTracking())
                                    <flux:badge size="sm" color="zinc">{{ __('Before tracking') }}</flux:badge>
                                @elseif ($shift->status !== 'closed')
                                    <flux:badge size="sm" color="sky">{{ __('Open') }}</flux:badge>
                                @elseif ($pending > 0)
                                    <flux:badge size="sm" color="amber">{{ trans_choice(':count pending|:count pending', $pending, ['count' => $pending]) }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Ready') }}</flux:badge>
                                @endif
                            </div>

                            @if ($shift->settlement)
                                <div class="mt-3 flex items-baseline justify-between text-sm">
                                    <span class="font-semibold">{{ number_format($shift->settlement->received_amount, 2) }}</span>
                                    @if ($shift->settlement->difference != 0)
                                        <span class="text-xs {{ $shift->settlement->isShort() ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                                            {{ $shift->settlement->difference > 0 ? '+' : '' }}{{ number_format($shift->settlement->difference, 2) }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </button>
                    @empty
                        <div class="rounded-xl border border-dashed border-zinc-200 p-4 text-center text-sm text-zinc-400 dark:border-zinc-700">
                            {{ __('No shift') }}
                        </div>
                    @endforelse
                </div>
            @endforeach
        </div>

        @if ($shift = $this->selectedShift)
            @php($summary = $this->shiftSummary)
            @php($settlement = $shift->settlement)
            @php($readOnly = $settlement !== null || $shift->isBeforeFinanceTracking())
            @php($pendingCount = $this->selectedPendingCount)

            <flux:card class="space-y-6" wire:key="shift-detail-{{ $shift->id }}">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <flux:heading size="lg">
                            {{ $shift->period()->label() }} · {{ $shift->user?->name ?? __('Unknown') }}
                        </flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ $shift->opened_at->format('D, M j H:i') }} → {{ $shift->closed_at?->format('D, M j H:i') ?? __('still open') }}
                        </flux:text>
                    </div>

                    @if (! $readOnly && $pendingCount > 0)
                        <flux:button variant="primary" icon="check-circle" wire:click="approveAll" wire:confirm="{{ __('Approve all :count pending expenses and returns on this shift?', ['count' => $pendingCount]) }}">
                            {{ __('Approve all (:count)', ['count' => $pendingCount]) }}
                        </flux:button>
                    @endif
                </div>

                {{-- Cash summary --}}
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="space-y-2 text-sm">
                        <flux:heading size="sm">{{ __('Cash summary') }}</flux:heading>

                        @php($rows = $settlement ? [
                            [__('Opening float'), $settlement->opening_balance, ''],
                            [__('Cash sales'), $settlement->cash_sales, '+'],
                            [__('Doctor payouts'), $settlement->doctor_payouts, '−'],
                            [__('Expenses'), $settlement->expenses, '−'],
                        ] : [
                            [__('Opening float'), $shift->opening_balance, ''],
                            [__('Walk-in cash'), $summary['walkin_cash'], '+'],
                            [__('Lab cash'), $summary['lab_cash'], '+'],
                            [__('Procedure cash'), $summary['procedure_cash'], '+'],
                            [__('Doctor payouts'), $summary['payouts'], '−'],
                            [__('Expenses (not declined)'), $summary['expenses'], '−'],
                        ])

                        @foreach ($rows as [$label, $amount, $sign])
                            <div class="flex justify-between border-b border-zinc-100 pb-1 dark:border-zinc-800">
                                <span class="text-zinc-600 dark:text-zinc-400">{{ $label }}</span>
                                <span class="tabular-nums">{{ $sign }} {{ number_format($amount, 2) }}</span>
                            </div>
                        @endforeach

                        <div class="flex justify-between pt-1 text-base font-semibold">
                            <span>{{ __('Expected in drawer') }}</span>
                            <span class="tabular-nums">{{ number_format($settlement?->expected_amount ?? $summary['expected'], 2) }}</span>
                        </div>

                        <div class="flex justify-between text-zinc-500">
                            <span>{{ __('Online (not in drawer)') }}</span>
                            <span class="tabular-nums">{{ number_format($settlement?->online_sales ?? $summary['online_sales'], 2) }}</span>
                        </div>
                        <div class="flex justify-between text-zinc-500">
                            <span>{{ __('Receptionist counted') }}</span>
                            <span class="tabular-nums">{{ ($settlement?->declared_closing_balance ?? $shift->closing_balance) !== null ? number_format($settlement?->declared_closing_balance ?? $shift->closing_balance, 2) : '—' }}</span>
                        </div>
                    </div>

                    {{-- Receive cash --}}
                    <div>
                        @if ($settlement)
                            <div class="rounded-xl border border-green-200 bg-green-50 p-5 dark:border-green-900 dark:bg-green-950/40">
                                <div class="flex items-center gap-2 text-sm font-medium text-green-800 dark:text-green-300">
                                    <flux:icon name="lock-closed" variant="mini" />
                                    {{ __('Settled by :name on :time', ['name' => $settlement->settler?->name ?? __('Unknown'), 'time' => $settlement->settled_at->format('M j, H:i')]) }}
                                </div>
                                <div class="mt-3 text-3xl font-bold tabular-nums">{{ number_format($settlement->received_amount, 2) }}</div>
                                <div class="mt-1 text-sm {{ $settlement->isShort() ? 'text-red-700 dark:text-red-400' : 'text-zinc-600 dark:text-zinc-400' }}">
                                    @if ($settlement->difference == 0)
                                        {{ __('Exact match') }}
                                    @elseif ($settlement->isShort())
                                        {{ __('Short by :amount', ['amount' => number_format(abs($settlement->difference), 2)]) }}
                                    @else
                                        {{ __('Over by :amount', ['amount' => number_format($settlement->difference, 2)]) }}
                                    @endif
                                </div>
                                @if ($settlement->notes)
                                    <flux:text class="mt-2">{{ $settlement->notes }}</flux:text>
                                @endif
                            </div>
                        @elseif ($shift->isBeforeFinanceTracking())
                            <flux:callout icon="archive-box" color="zinc" :heading="__('Before finance tracking')" :text="__('Shifts before :date are not settled here.', ['date' => \App\Models\Shift::financeTrackingStartedAt()?->format('M j, Y')])" />
                        @elseif ($shift->status !== 'closed')
                            <flux:callout icon="clock" color="sky" :heading="__('Shift still open')" :text="__('Cash can be received once the receptionist closes this shift.')" />
                        @else
                            <form wire:submit="settle" class="space-y-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                                <flux:field>
                                    <flux:label>{{ __('Amount received') }}</flux:label>
                                    <flux:input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        inputmode="decimal"
                                        wire:model="receivedAmount"
                                        placeholder="{{ number_format($summary['expected'], 2, '.', '') }}"
                                        class:input="h-16! text-3xl! font-bold tabular-nums"
                                        :disabled="$pendingCount > 0"
                                    />
                                    <flux:error name="receivedAmount" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>{{ __('Notes (optional)') }}</flux:label>
                                    <flux:input wire:model="settlementNotes" :disabled="$pendingCount > 0" />
                                    <flux:error name="settlementNotes" />
                                </flux:field>

                                @if ($pendingCount > 0)
                                    <flux:text class="text-amber-700 dark:text-amber-400">
                                        {{ __('Approve or decline the :count pending items below first.', ['count' => $pendingCount]) }}
                                    </flux:text>
                                @endif

                                <flux:button
                                    type="submit"
                                    variant="primary"
                                    icon="lock-closed"
                                    class="w-full"
                                    :disabled="$pendingCount > 0"
                                    wire:confirm="{{ __('Lock this shift? The received amount cannot be edited afterwards.') }}"
                                >
                                    {{ __('Receive cash & lock shift') }}
                                </flux:button>
                            </form>
                        @endif
                    </div>
                </div>

                {{-- Expenses --}}
                <div>
                    <flux:heading size="sm" class="mb-2">{{ __('Expenses') }} ({{ $this->shiftExpenses->count() }})</flux:heading>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Amount') }}</flux:table.column>
                            <flux:table.column>{{ __('Logged by') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->shiftExpenses as $expense)
                                <flux:table.row wire:key="shift-expense-{{ $expense->id }}">
                                    <flux:table.cell>
                                        {{ $expense->name }}
                                        @if ($expense->wasEdited())
                                            <div class="text-xs text-zinc-500">
                                                <flux:badge size="sm" color="amber">{{ __('Edited') }}</flux:badge>
                                                {{ __('was :old', ['old' => $expense->previous_name]) }}
                                                @if ((float) $expense->previous_amount !== (float) $expense->amount)
                                                    · {{ number_format($expense->previous_amount, 2) }}
                                                @endif
                                            </div>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right tabular-nums {{ $expense->isRejected() ? 'text-zinc-400 line-through' : '' }}">{{ number_format($expense->amount, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $expense->user?->name ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" :color="match ($expense->approval_status) { \App\Enums\ApprovalStatus::Approved => 'green', \App\Enums\ApprovalStatus::Rejected => 'red', default => 'amber' }">
                                            {{ $expense->approval_status === \App\Enums\ApprovalStatus::Rejected ? __('Declined') : $expense->approval_status->label() }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        @if (! $readOnly && $expense->isPendingApproval())
                                            <flux:button size="sm" variant="primary" wire:click="approveExpense({{ $expense->id }})">{{ __('Approve') }}</flux:button>
                                            <flux:button size="sm" variant="danger" wire:click="declineExpense({{ $expense->id }})" wire:confirm="{{ __('Decline this expense? It will be removed from the shift cash.') }}">{{ __('Decline') }}</flux:button>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('No expenses on this shift.') }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>

                {{-- Returns --}}
                <div>
                    <flux:heading size="sm" class="mb-2">{{ __('Returns') }} ({{ $this->shiftReturns->count() }})</flux:heading>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Type') }}</flux:table.column>
                            <flux:table.column>{{ __('Reference') }}</flux:table.column>
                            <flux:table.column>{{ __('Patient') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Amount') }}</flux:table.column>
                            <flux:table.column>{{ __('Requested by') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->shiftReturns as $return)
                                <flux:table.row wire:key="shift-return-{{ $return['type'] }}-{{ $return['id'] }}">
                                    <flux:table.cell><flux:badge size="sm" color="zinc">{{ $return['type_label'] }}</flux:badge></flux:table.cell>
                                    <flux:table.cell>{{ $return['reference'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $return['patient'] }}</flux:table.cell>
                                    <flux:table.cell class="text-right tabular-nums">{{ number_format($return['amount'], 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $return['requested_by'] }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" :color="$return['status'] === \App\Enums\ApprovalStatus::Approved ? 'green' : 'amber'">
                                            {{ $return['status']?->label() ?? __('Approved') }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        @if (! $readOnly && $return['status'] === \App\Enums\ApprovalStatus::Pending)
                                            <flux:button size="sm" variant="primary" wire:click="approveReturn({{ $return['id'] }}, '{{ $return['type'] }}')">{{ __('Approve') }}</flux:button>
                                            <flux:button size="sm" variant="danger" wire:click="declineReturn({{ $return['id'] }}, '{{ $return['type'] }}')" wire:confirm="{{ __('Decline this return? The sale will be restored to the shift cash.') }}">{{ __('Decline') }}</flux:button>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="7" class="text-center text-zinc-500">{{ __('No returns on this shift.') }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </flux:card>
        @endif
    @elseif ($activeTab === 'month')
        @php($overview = $this->monthOverview)

        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="previousMonth" />
            <flux:text class="min-w-36 text-center font-semibold">{{ $this->monthLabel }}</flux:text>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="nextMonth" />
        </div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Cash received') }}</flux:text>
                <flux:heading level="2" class="mt-1 text-green-700 dark:text-green-400">{{ number_format($overview['received'], 2) }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Short / Over') }}</flux:text>
                <flux:heading level="2" class="mt-1 {{ $overview['difference'] < 0 ? 'text-red-700 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">
                    {{ $overview['difference'] > 0 ? '+' : '' }}{{ number_format($overview['difference'], 2) }}
                </flux:heading>
            </flux:card>
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Hospital expenses') }}</flux:text>
                <flux:heading level="2" class="mt-1 text-red-700 dark:text-red-400">{{ number_format($overview['hospital_expenses'], 2) }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text class="text-zinc-500">{{ __('Net') }}</flux:text>
                <flux:heading level="2" class="mt-1 {{ $overview['net'] >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">{{ number_format($overview['net'], 2) }}</flux:heading>
            </flux:card>
        </div>

        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Shifts settled') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Expected') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Received') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Short / Over') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($overview['days'] as $day)
                        <flux:table.row wire:key="month-day-{{ $day['date']->toDateString() }}">
                            <flux:table.cell>
                                <button type="button" class="cursor-pointer font-medium hover:underline" wire:click="showDay('{{ $day['date']->toDateString() }}')">
                                    {{ $day['date']->format('D, M j') }}
                                </button>
                            </flux:table.cell>
                            <flux:table.cell class="text-right">{{ $day['shifts'] }}</flux:table.cell>
                            <flux:table.cell class="text-right tabular-nums">{{ number_format($day['expected'], 2) }}</flux:table.cell>
                            <flux:table.cell class="text-right tabular-nums font-medium">{{ number_format($day['received'], 2) }}</flux:table.cell>
                            <flux:table.cell class="text-right tabular-nums {{ $day['difference'] < 0 ? 'text-red-700 dark:text-red-400' : '' }}">
                                {{ $day['difference'] > 0 ? '+' : '' }}{{ number_format($day['difference'], 2) }}
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="py-8 text-center text-zinc-500">{{ __('No shifts settled this month.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @else
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="previousMonth" />
            <flux:text class="min-w-36 text-center font-semibold">{{ $this->monthLabel }}</flux:text>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="nextMonth" />
        </div>

        <flux:card>
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:text>{{ __('Salary, rent and other hospital expenses (not reception shift expenses).') }}</flux:text>
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
