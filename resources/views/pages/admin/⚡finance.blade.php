<?php

use App\Actions\ApproveExpense;
use App\Actions\ApprovePendingShiftItems;
use App\Actions\ApproveReturn;
use App\Actions\RejectExpense;
use App\Actions\RejectReturn;
use App\Actions\SettleShift;
use App\Actions\UpdateShiftSettlement;
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
    public string $date = '';

    public ?int $selectedShiftId = null;

    public string $receivedAmount = '';

    public string $settlementNotes = '';

    public bool $editingSettlement = false;

    public string $editReceivedAmount = '';

    public string $editSettlementNotes = '';

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
        $this->expenseDate = now()->toDateString();
        $this->expenseCategory = FinanceExpenseCategory::Salary->value;
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

    public function goToToday(): void
    {
        $this->date = now()->toDateString();
        $this->deselectShift();
    }

    public function selectShift(int $shiftId): void
    {
        $this->selectedShiftId = $this->selectedShiftId === $shiftId ? null : $shiftId;
        $this->resetSettlementForms();
    }

    public function deselectShift(): void
    {
        $this->selectedShiftId = null;
        $this->resetSettlementForms();
    }

    /**
     * Start correcting the selected shift's settlement (admins only).
     */
    public function startEditingSettlement(): void
    {
        $settlement = $this->selectedShift?->settlement;

        if ($settlement === null || ! auth()->user()->isAdmin()) {
            return;
        }

        $this->editReceivedAmount = number_format($settlement->received_amount, 2, '.', '');
        $this->editSettlementNotes = $settlement->notes ?? '';
        $this->resetValidation();
        $this->editingSettlement = true;
    }

    public function cancelEditingSettlement(): void
    {
        $this->editingSettlement = false;
        $this->resetValidation();
    }

    public function saveSettlementEdit(): void
    {
        $settlement = $this->selectedShift?->settlement;

        if ($settlement === null) {
            return;
        }

        $validated = $this->validate([
            'editReceivedAmount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'editSettlementNotes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'editReceivedAmount' => __('amount received'),
            'editSettlementNotes' => __('notes'),
        ]);

        app(UpdateShiftSettlement::class)->handle(
            auth()->user(),
            $settlement,
            (float) $validated['editReceivedAmount'],
            $validated['editSettlementNotes'] ?? null,
        );

        $this->editingSettlement = false;
        $this->refreshShift();
        unset($this->monthOverview);

        Flux::toast(variant: 'success', text: __('Settlement updated.'));
    }

    private function resetSettlementForms(): void
    {
        $this->receivedAmount = '';
        $this->settlementNotes = '';
        $this->editingSettlement = false;
        $this->editReceivedAmount = '';
        $this->editSettlementNotes = '';
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
     * @return array{expected: float, received: float, difference: float, expenses: float, net: float, settled: int, total: int}
     */
    #[Computed]
    public function dayTotals(): array
    {
        $shifts = $this->shiftsByPeriod->flatten();
        $settlements = $shifts->pluck('settlement')->filter();
        $received = (float) $settlements->sum('received_amount');
        $expenses = (float) $this->dayExpenses->sum('amount');

        return [
            'expected' => (float) $settlements->sum('expected_amount'),
            'received' => $received,
            'difference' => (float) $settlements->sum('difference'),
            'expenses' => $expenses,
            'net' => $received - $expenses,
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

        return Shift::with(['user', 'settlement.settler', 'settlement.editor'])->find($this->selectedShiftId);
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
     * Month-to-date totals for the month of the picked date.
     *
     * @return array{expected: float, received: float, difference: float, expenses: float, net: float, settled: int}
     */
    #[Computed]
    public function monthOverview(): array
    {
        $date = Carbon::parse($this->date);

        $settlements = ShiftSettlement::query()
            ->whereYear('business_date', $date->year)
            ->whereMonth('business_date', $date->month)
            ->get();

        $expenses = (float) FinanceExpense::query()
            ->whereYear('expense_date', $date->year)
            ->whereMonth('expense_date', $date->month)
            ->sum('amount');

        $received = (float) $settlements->sum('received_amount');

        return [
            'expected' => (float) $settlements->sum('expected_amount'),
            'received' => $received,
            'difference' => (float) $settlements->sum('difference'),
            'expenses' => $expenses,
            'net' => $received - $expenses,
            'settled' => $settlements->count(),
        ];
    }

    /**
     * Hospital expenses (salaries, rent, etc.) recorded against the picked date.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, FinanceExpense>
     */
    #[Computed]
    public function dayExpenses()
    {
        return FinanceExpense::query()
            ->with('user')
            ->whereDate('expense_date', $this->date)
            ->oldest()
            ->get();
    }

    #[Computed]
    public function monthLabel(): string
    {
        return Carbon::parse($this->date)->format('F Y');
    }

    public function openExpenseModal(): void
    {
        $this->resetExpenseForm();
        $this->expenseDate = $this->date;
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
        unset($this->dayExpenses, $this->dayTotals, $this->monthOverview);
    }

    public function deleteExpense(int $id): void
    {
        FinanceExpense::query()->whereKey($id)->delete();
        unset($this->dayExpenses, $this->dayTotals, $this->monthOverview);

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
            $this->monthOverview,
        );
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-5">
    @php($totals = $this->dayTotals)
    @php($overview = $this->monthOverview)
    @php($money = fn (?float $value): string => number_format((float) $value, fmod((float) $value, 1.0) == 0.0 ? 0 : 2))
    @php($signed = fn (float $value): string => ($value > 0 ? '+' : ($value < 0 ? '−' : '')).number_format(abs($value), fmod($value, 1.0) == 0.0 ? 0 : 2))
    @php($pickedDate = \Illuminate\Support\Carbon::parse($date))

    <div>
        <flux:heading size="xl" level="1">{{ __('Finance') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Pick a day, open each shift, check it, and record the cash you received.') }}</flux:text>
    </div>

    {{-- Month summary --}}
    <div class="rounded-2xl bg-zinc-900 p-5 text-white ring-1 ring-zinc-900 dark:bg-zinc-800 dark:ring-zinc-600">
        <div class="flex items-center justify-between gap-2 text-sm text-zinc-300">
            <span>{{ __(':month total', ['month' => $this->monthLabel]) }}</span>
            <span>{{ trans_choice(':count shift received|:count shifts received', $overview['settled'], ['count' => $overview['settled']]) }}</span>
        </div>
        <div class="mt-2 text-xs uppercase tracking-wide text-zinc-400">{{ __('Cash in hand (received − expenses)') }}</div>
        <div class="text-4xl font-bold tabular-nums">{{ $money($overview['net']) }}</div>
        <div class="mt-4 grid grid-cols-3 gap-2 text-sm">
            <div class="rounded-xl bg-white/10 p-3">
                <div class="text-xs text-zinc-300">{{ __('Received') }}</div>
                <div class="font-semibold tabular-nums">{{ $money($overview['received']) }}</div>
            </div>
            <div class="rounded-xl bg-white/10 p-3">
                <div class="text-xs text-zinc-300">{{ __('Expenses') }}</div>
                <div class="font-semibold tabular-nums">{{ $money($overview['expenses']) }}</div>
            </div>
            <div class="rounded-xl bg-white/10 p-3">
                <div class="text-xs text-zinc-300">{{ __('Short/Over') }}</div>
                <div class="font-semibold tabular-nums {{ $overview['difference'] < 0 ? 'text-red-300' : ($overview['difference'] > 0 ? 'text-green-300' : '') }}">{{ $signed($overview['difference']) }}</div>
            </div>
        </div>
    </div>

    {{-- Day picker --}}
    <div class="rounded-2xl border border-zinc-200 p-3 dark:border-zinc-700">
        <div class="flex items-center gap-2">
            <flux:button icon="chevron-left" wire:click="previousDay" :aria-label="__('Previous day')" />
            <label class="relative flex-1 cursor-pointer text-center">
                <span class="block text-lg font-semibold">{{ $pickedDate->format('D, j M Y') }}</span>
                <span class="block text-xs text-zinc-500">
                    @if ($pickedDate->isToday())
                        {{ __('Today') }}
                    @elseif ($pickedDate->isYesterday())
                        {{ __('Yesterday') }}
                    @else
                        {{ __('Tap to change date') }}
                    @endif
                </span>
                <input
                    type="date"
                    wire:model.live="date"
                    class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                    aria-label="{{ __('Date') }}"
                    onclick="this.showPicker?.()"
                />
            </label>
            <flux:button icon="chevron-right" wire:click="nextDay" :aria-label="__('Next day')" />
        </div>
        @unless ($pickedDate->isToday())
            <div class="mt-2 text-center">
                <flux:button size="sm" variant="ghost" icon="calendar" wire:click="goToToday">{{ __('Go to today') }}</flux:button>
            </div>
        @endunless

        <div class="mt-3 grid grid-cols-2 gap-2 border-t border-zinc-100 pt-3 text-sm sm:grid-cols-4 dark:border-zinc-800">
            <div>
                <div class="text-xs text-zinc-500">{{ __('Shifts done') }}</div>
                <div class="font-semibold">{{ $totals['settled'] }} / {{ $totals['total'] }}</div>
            </div>
            <div>
                <div class="text-xs text-zinc-500">{{ __('Received') }}</div>
                <div class="font-semibold tabular-nums text-green-700 dark:text-green-400">{{ $money($totals['received']) }}</div>
            </div>
            <div>
                <div class="text-xs text-zinc-500">{{ __('Expenses') }}</div>
                <div class="font-semibold tabular-nums text-red-700 dark:text-red-400">{{ $money($totals['expenses']) }}</div>
            </div>
            <div>
                <div class="text-xs text-zinc-500">{{ __('Short/Over') }}</div>
                <div class="font-semibold tabular-nums {{ $totals['difference'] < 0 ? 'text-red-700 dark:text-red-400' : '' }}">{{ $signed($totals['difference']) }}</div>
            </div>
        </div>
    </div>

    {{-- Shifts --}}
    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Shifts') }}</flux:heading>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            @foreach ($this->shiftsByPeriod as $periodValue => $shifts)
                @php($period = \App\Enums\FinanceShiftPeriod::from($periodValue))
                @php($periodIcon = match ($period) { \App\Enums\FinanceShiftPeriod::Night => 'moon', \App\Enums\FinanceShiftPeriod::Morning => 'sun', \App\Enums\FinanceShiftPeriod::Evening => 'cloud' })

                @forelse ($shifts as $shift)
                    @php($pending = $this->pendingCounts[$shift->id] ?? 0)
                    @php($isSelected = $selectedShiftId === $shift->id)
                    @php($state = match (true) {
                        $shift->settlement !== null => 'done',
                        $shift->isBeforeFinanceTracking() => 'old',
                        $shift->status !== 'closed' => 'open',
                        $pending > 0 => 'review',
                        default => 'ready',
                    })
                    @php($stateStyle = [
                        'done' => ['border-green-300 bg-green-50 dark:border-green-900 dark:bg-green-950/40', 'text-green-700 dark:text-green-400', 'check-circle', __('Cash received')],
                        'old' => ['border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50', 'text-zinc-500', 'archive-box', __('Before tracking')],
                        'open' => ['border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/40', 'text-sky-700 dark:text-sky-400', 'clock', __('Shift still running')],
                        'review' => ['border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40', 'text-amber-700 dark:text-amber-400', 'exclamation-circle', trans_choice(':count item to check|:count items to check', $pending, ['count' => $pending])],
                        'ready' => ['border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-900', 'text-zinc-700 dark:text-zinc-300', 'banknotes', __('Ready to receive cash')],
                    ][$state])

                    <button
                        type="button"
                        wire:key="shift-card-{{ $shift->id }}"
                        wire:click="selectShift({{ $shift->id }})"
                        class="w-full cursor-pointer rounded-2xl border-2 p-4 text-left transition {{ $stateStyle[0] }} {{ $isSelected ? 'ring-2 ring-zinc-900 ring-offset-2 dark:ring-white dark:ring-offset-zinc-900' : 'hover:shadow-md' }}"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 font-semibold">
                                <flux:icon :name="$periodIcon" variant="mini" class="text-zinc-400" />
                                {{ $period->label() }}
                            </div>
                            <flux:icon :name="$isSelected ? 'chevron-up' : 'chevron-down'" variant="mini" class="text-zinc-400" />
                        </div>
                        <div class="mt-2 font-medium">{{ $shift->user?->name ?? __('Unknown') }}</div>
                        <div class="text-xs text-zinc-500">{{ $shift->opened_at->format('D j M, g:i A') }} → {{ $shift->closed_at?->format('g:i A') ?? __('now') }}</div>
                        <div class="mt-3 flex items-center justify-between gap-2 text-sm font-medium {{ $stateStyle[1] }}">
                            <span class="flex items-center gap-1">
                                <flux:icon :name="$stateStyle[2]" variant="micro" />
                                {{ $stateStyle[3] }}
                            </span>
                            @if ($shift->settlement)
                                <span class="tabular-nums">{{ $money($shift->settlement->received_amount) }}</span>
                            @endif
                        </div>
                    </button>
                @empty
                    <div wire:key="period-empty-{{ $periodValue }}" class="flex items-center gap-2 rounded-2xl border-2 border-dashed border-zinc-200 p-4 text-sm text-zinc-400 dark:border-zinc-700">
                        <flux:icon :name="$periodIcon" variant="mini" />
                        {{ __(':period — no shift', ['period' => $period->label()]) }}
                    </div>
                @endforelse
            @endforeach
        </div>
    </div>

    {{-- Selected shift: guided steps --}}
    @if ($shift = $this->selectedShift)
        @php($summary = $this->shiftSummary)
        @php($settlement = $shift->settlement)
        @php($pendingCount = $this->selectedPendingCount)
        @php($readOnly = $settlement !== null || $shift->isBeforeFinanceTracking())
        @php($expected = $settlement?->expected_amount ?? $summary['expected'])
        @php($itemsCount = $this->shiftExpenses->count() + $this->shiftReturns->count())

        <div
            wire:key="shift-detail-{{ $shift->id }}"
            x-data
            x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
            class="scroll-mt-4 overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700"
        >
            <div class="flex items-start justify-between gap-2 bg-zinc-50 p-4 dark:bg-zinc-800/60">
                <div>
                    <flux:heading size="lg">{{ $shift->period()->label() }} · {{ $shift->user?->name ?? __('Unknown') }}</flux:heading>
                    <flux:text class="text-sm">{{ $shift->opened_at->format('D j M, g:i A') }} → {{ $shift->closed_at?->format('D j M, g:i A') ?? __('still open') }}</flux:text>
                </div>
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="deselectShift" :aria-label="__('Close')" />
            </div>

            <div class="space-y-4 p-4">
                @if ($shift->isBeforeFinanceTracking())
                    <flux:callout icon="archive-box" color="zinc" :heading="__('Before finance tracking')" :text="__('Shifts before :date are not received here. You can only look at them.', ['date' => \App\Models\Shift::financeTrackingStartedAt()?->format('j M Y')])" />
                @elseif ($shift->status !== 'closed')
                    <flux:callout icon="clock" color="sky" :heading="__('Shift still running')" :text="__('Wait until the receptionist closes this shift, then come back to receive the cash.')" />
                @endif

                {{-- Step 1: check items --}}
                @php($stepOneDone = $pendingCount === 0)
                <section class="rounded-2xl border border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center gap-3 p-4">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-bold {{ $stepOneDone ? 'bg-green-600 text-white' : 'bg-amber-500 text-white' }}">
                            @if ($stepOneDone) <flux:icon name="check" variant="micro" /> @else 1 @endif
                        </span>
                        <div class="flex-1">
                            <div class="font-semibold">{{ __('Check expenses & returns') }}</div>
                            <div class="text-sm text-zinc-500">
                                @if ($itemsCount === 0)
                                    {{ __('Nothing to check on this shift.') }}
                                @elseif ($stepOneDone)
                                    {{ trans_choice('All :count item checked.|All :count items checked.', $itemsCount, ['count' => $itemsCount]) }}
                                @else
                                    {{ trans_choice(':count item needs your OK.|:count items need your OK.', $pendingCount, ['count' => $pendingCount]) }}
                                @endif
                            </div>
                        </div>
                    </div>

                    @if (! $readOnly && $pendingCount > 0)
                        <div class="px-4 pb-3">
                            <flux:button
                                variant="primary"
                                icon="check-circle"
                                class="w-full"
                                wire:click="approveAll"
                                wire:confirm="{{ __('Approve all :count items on this shift?', ['count' => $pendingCount]) }}"
                            >
                                {{ __('Approve all (:count)', ['count' => $pendingCount]) }}
                            </flux:button>
                        </div>
                    @endif

                    @if ($itemsCount > 0)
                        <details class="group border-t border-zinc-100 dark:border-zinc-800" @if (! $stepOneDone) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between p-4 text-sm font-medium text-zinc-600 dark:text-zinc-300">
                                <span>{{ __('See the list (:expenses expenses, :returns returns)', ['expenses' => $this->shiftExpenses->count(), 'returns' => $this->shiftReturns->count()]) }}</span>
                                <flux:icon name="chevron-down" variant="mini" class="transition group-open:rotate-180" />
                            </summary>

                            <ul class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
                                @foreach ($this->shiftExpenses as $expense)
                                    <li wire:key="shift-expense-{{ $expense->id }}" class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <flux:badge size="sm" color="zinc">{{ __('Expense') }}</flux:badge>
                                                <span class="truncate font-medium">{{ $expense->name }}</span>
                                            </div>
                                            <div class="mt-0.5 text-xs text-zinc-500">
                                                {{ __('by :name', ['name' => $expense->user?->name ?? '-']) }}
                                                @if ($expense->wasEdited())
                                                    · <span class="text-amber-600">{{ __('edited, was :old :amount', ['old' => $expense->previous_name, 'amount' => $money($expense->previous_amount)]) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="flex items-center justify-between gap-3 sm:justify-end">
                                            <span class="text-lg font-semibold tabular-nums {{ $expense->isRejected() ? 'text-zinc-400 line-through' : '' }}">{{ $money($expense->amount) }}</span>
                                            @if (! $readOnly && $expense->isPendingApproval())
                                                <div class="flex gap-2">
                                                    <flux:button size="sm" variant="primary" icon="check" wire:click="approveExpense({{ $expense->id }})">{{ __('Approve') }}</flux:button>
                                                    <flux:button size="sm" variant="danger" icon="x-mark" wire:click="declineExpense({{ $expense->id }})" wire:confirm="{{ __('Decline this expense? It will be removed from the shift cash.') }}">{{ __('Decline') }}</flux:button>
                                                </div>
                                            @else
                                                <flux:badge size="sm" :color="match ($expense->approval_status) { \App\Enums\ApprovalStatus::Approved => 'green', \App\Enums\ApprovalStatus::Rejected => 'red', default => 'amber' }">
                                                    {{ $expense->isRejected() ? __('Declined') : $expense->approval_status->label() }}
                                                </flux:badge>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach

                                @foreach ($this->shiftReturns as $return)
                                    <li wire:key="shift-return-{{ $return['type'] }}-{{ $return['id'] }}" class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <flux:badge size="sm" color="purple">{{ __(':type return', ['type' => $return['type_label']]) }}</flux:badge>
                                                <span class="truncate font-medium">{{ $return['patient'] }}</span>
                                            </div>
                                            <div class="mt-0.5 text-xs text-zinc-500">{{ $return['reference'] }} · {{ __('by :name', ['name' => $return['requested_by']]) }}</div>
                                        </div>
                                        <div class="flex items-center justify-between gap-3 sm:justify-end">
                                            <span class="text-lg font-semibold tabular-nums">{{ $money($return['amount']) }}</span>
                                            @if (! $readOnly && $return['status'] === \App\Enums\ApprovalStatus::Pending)
                                                <div class="flex gap-2">
                                                    <flux:button size="sm" variant="primary" icon="check" wire:click="approveReturn({{ $return['id'] }}, '{{ $return['type'] }}')">{{ __('Approve') }}</flux:button>
                                                    <flux:button size="sm" variant="danger" icon="x-mark" wire:click="declineReturn({{ $return['id'] }}, '{{ $return['type'] }}')" wire:confirm="{{ __('Decline this return? The sale will be added back to the shift cash.') }}">{{ __('Decline') }}</flux:button>
                                                </div>
                                            @else
                                                <flux:badge size="sm" :color="$return['status'] === \App\Enums\ApprovalStatus::Pending ? 'amber' : 'green'">
                                                    {{ $return['status']?->label() ?? __('Approved') }}
                                                </flux:badge>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </section>

                {{-- Step 2: money in the drawer --}}
                <section class="rounded-2xl border border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center gap-3 p-4">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-900 text-sm font-bold text-white dark:bg-white dark:text-zinc-900">2</span>
                        <div class="flex-1">
                            <div class="font-semibold">{{ __('Cash that should be in the drawer') }}</div>
                            <div class="text-3xl font-bold tabular-nums">{{ $money($expected) }}</div>
                        </div>
                    </div>

                    <details class="group border-t border-zinc-100 dark:border-zinc-800">
                        <summary class="flex cursor-pointer list-none items-center justify-between p-4 text-sm font-medium text-zinc-600 dark:text-zinc-300">
                            <span>{{ __('How is this calculated?') }}</span>
                            <flux:icon name="chevron-down" variant="mini" class="transition group-open:rotate-180" />
                        </summary>

                        @php($rows = $settlement ? [
                            [__('Starting cash (float)'), $settlement->opening_balance, ''],
                            [__('Cash sales'), $settlement->cash_sales, '+'],
                            [__('Doctor payouts'), $settlement->doctor_payouts, '−'],
                            [__('Shift expenses'), $settlement->expenses, '−'],
                        ] : [
                            [__('Starting cash (float)'), $shift->opening_balance, ''],
                            [__('Walk-in cash'), $summary['walkin_cash'], '+'],
                            [__('Lab cash'), $summary['lab_cash'], '+'],
                            [__('Procedure cash'), $summary['procedure_cash'], '+'],
                            [__('Doctor payouts'), $summary['payouts'], '−'],
                            [__('Shift expenses'), $summary['expenses'], '−'],
                        ])

                        <dl class="space-y-2 px-4 pb-4 text-sm">
                            @foreach ($rows as [$label, $amount, $sign])
                                <div class="flex justify-between gap-2">
                                    <dt class="text-zinc-600 dark:text-zinc-400">{{ $label }}</dt>
                                    <dd class="tabular-nums">{{ $sign }} {{ $money($amount) }}</dd>
                                </div>
                            @endforeach
                            <div class="flex justify-between gap-2 border-t border-zinc-200 pt-2 font-semibold dark:border-zinc-700">
                                <dt>{{ __('Should be in drawer') }}</dt>
                                <dd class="tabular-nums">{{ $money($expected) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 text-zinc-500">
                                <dt>{{ __('Paid online (not in drawer)') }}</dt>
                                <dd class="tabular-nums">{{ $money($settlement?->online_sales ?? $summary['online_sales']) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 text-zinc-500">
                                <dt>{{ __('Receptionist counted') }}</dt>
                                <dd class="tabular-nums">{{ ($settlement?->declared_closing_balance ?? $shift->closing_balance) !== null ? $money($settlement?->declared_closing_balance ?? $shift->closing_balance) : '—' }}</dd>
                            </div>
                        </dl>
                    </details>
                </section>

                {{-- Step 3: receive cash --}}
                <section class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="mb-3 flex items-center gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-bold {{ $settlement ? 'bg-green-600 text-white' : 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' }}">
                            @if ($settlement) <flux:icon name="check" variant="micro" /> @else 3 @endif
                        </span>
                        <div class="font-semibold">{{ $settlement ? __('Cash received') : __('Enter the cash you received') }}</div>
                    </div>

                    @if ($settlement && $editingSettlement)
                        <form wire:submit="saveSettlementEdit" class="space-y-3">
                            <flux:callout icon="pencil-square" color="amber" :text="__('You are correcting a locked shift. The old amount will be kept in the history.')" />

                            <flux:field>
                                <flux:label>{{ __('Amount received') }}</flux:label>
                                <flux:input type="number" step="0.01" min="0" inputmode="decimal" wire:model="editReceivedAmount" class:input="h-16! text-3xl! font-bold tabular-nums" />
                                <flux:error name="editReceivedAmount" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Note (optional)') }}</flux:label>
                                <flux:input wire:model="editSettlementNotes" :placeholder="__('Why is it being changed?')" />
                                <flux:error name="editSettlementNotes" />
                            </flux:field>

                            <div class="grid grid-cols-2 gap-2">
                                <flux:button type="button" wire:click="cancelEditingSettlement">{{ __('Cancel') }}</flux:button>
                                <flux:button type="submit" variant="primary">{{ __('Save correction') }}</flux:button>
                            </div>
                        </form>
                    @elseif ($settlement)
                        <div class="rounded-xl bg-green-50 p-4 dark:bg-green-950/40">
                            <div class="text-4xl font-bold tabular-nums">{{ $money($settlement->received_amount) }}</div>
                            <div class="mt-1 font-medium {{ $settlement->isShort() ? 'text-red-700 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">
                                @if ($settlement->difference == 0)
                                    {{ __('Exact — nothing missing') }}
                                @elseif ($settlement->isShort())
                                    {{ __(':amount short', ['amount' => $money(abs($settlement->difference))]) }}
                                @else
                                    {{ __(':amount extra', ['amount' => $money($settlement->difference)]) }}
                                @endif
                            </div>
                            @if ($settlement->notes)
                                <div class="mt-2 text-sm">“{{ $settlement->notes }}”</div>
                            @endif
                            <div class="mt-3 flex items-center gap-1 text-xs text-zinc-500">
                                <flux:icon name="lock-closed" variant="micro" />
                                {{ __('Locked by :name on :time', ['name' => $settlement->settler?->name ?? __('Unknown'), 'time' => $settlement->settled_at->format('j M, g:i A')]) }}
                            </div>
                            @if ($settlement->wasEdited())
                                <div class="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                    {{ __('Corrected by :name on :time', ['name' => $settlement->editor?->name ?? __('Unknown'), 'time' => $settlement->edited_at->format('j M, g:i A')]) }}
                                    @if ($settlement->previous_received_amount !== null)
                                        · {{ __('was :amount', ['amount' => $money($settlement->previous_received_amount)]) }}
                                    @endif
                                </div>
                            @endif
                        </div>
                        @if (auth()->user()->isAdmin())
                            <flux:button class="mt-3 w-full" icon="pencil-square" wire:click="startEditingSettlement">{{ __('Correct this amount') }}</flux:button>
                        @endif
                    @elseif ($readOnly || $shift->status !== 'closed')
                        <flux:text>{{ __('Not available for this shift.') }}</flux:text>
                    @elseif ($pendingCount > 0)
                        <flux:callout icon="arrow-up" color="amber" :text="__('First finish step 1: approve or decline the :count items above.', ['count' => $pendingCount])" />
                    @else
                        <form
                            wire:submit="settle"
                            class="space-y-3"
                            x-data="{ expected: {{ json_encode(round($expected, 2)) }}, get typed() { const value = parseFloat(this.$wire.receivedAmount); return isNaN(value) ? null : value }, get gap() { return this.typed === null ? null : Math.round((this.typed - this.expected) * 100) / 100 } }"
                        >
                            <flux:field>
                                <flux:label>{{ __('Amount received') }}</flux:label>
                                <flux:input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    inputmode="decimal"
                                    wire:model="receivedAmount"
                                    :placeholder="$money($expected)"
                                    class:input="h-16! text-3xl! font-bold tabular-nums"
                                />
                                <flux:error name="receivedAmount" />
                            </flux:field>

                            <div x-show="gap !== null" x-cloak class="rounded-xl p-3 text-center font-semibold" :class="gap < 0 ? 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400' : (gap > 0 ? 'bg-green-50 text-green-700 dark:bg-green-950/40 dark:text-green-400' : 'bg-zinc-100 dark:bg-zinc-800')">
                                <span x-show="gap < 0">{{ __('Short by') }} <span x-text="Math.abs(gap ?? 0).toLocaleString()"></span></span>
                                <span x-show="gap > 0">{{ __('Extra') }} <span x-text="(gap ?? 0).toLocaleString()"></span></span>
                                <span x-show="gap === 0">{{ __('Exact — nothing missing') }}</span>
                            </div>

                            <flux:field>
                                <flux:label>{{ __('Note (optional)') }}</flux:label>
                                <flux:input wire:model="settlementNotes" :placeholder="__('e.g. 500 given to Dr. X')" />
                                <flux:error name="settlementNotes" />
                            </flux:field>

                            <flux:button
                                type="submit"
                                variant="primary"
                                icon="lock-closed"
                                class="h-12! w-full text-base!"
                                wire:confirm="{{ __('Lock this shift? You will not be able to change the amount afterwards.') }}"
                            >
                                {{ __('Save & lock shift') }}
                            </flux:button>
                        </form>
                    @endif
                </section>
            </div>
        </div>
    @endif

    {{-- Day expenses --}}
    <div>
        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="lg">{{ __('Other expenses') }}</flux:heading>
                <flux:text class="text-sm">{{ __('Salaries, rent, bills… paid on :date', ['date' => $pickedDate->format('j M')]) }}</flux:text>
            </div>
            <flux:button variant="primary" icon="plus" class="w-full sm:w-auto" wire:click="openExpenseModal">
                {{ __('Add expense') }}
            </flux:button>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700">
            @forelse ($this->dayExpenses as $expense)
                <div wire:key="finance-expense-{{ $expense->id }}" class="flex items-center gap-3 border-b border-zinc-100 p-4 last:border-b-0 dark:border-zinc-800">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate font-medium">{{ $expense->name }}</span>
                            <flux:badge size="sm" :color="$expense->category === \App\Enums\FinanceExpenseCategory::Salary ? 'blue' : 'zinc'">{{ $expense->category->label() }}</flux:badge>
                        </div>
                        <div class="mt-0.5 truncate text-xs text-zinc-500">
                            {{ __('by :name', ['name' => $expense->user?->name ?? '-']) }}@if ($expense->notes) · {{ $expense->notes }}@endif
                        </div>
                    </div>
                    <span class="text-lg font-semibold tabular-nums">{{ $money($expense->amount) }}</span>
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" :aria-label="__('Options')" />
                        <flux:menu>
                            <flux:menu.item icon="pencil-square" wire:click="editExpense({{ $expense->id }})">{{ __('Edit') }}</flux:menu.item>
                            <flux:menu.item icon="trash" variant="danger" wire:click="deleteExpense({{ $expense->id }})" wire:confirm="{{ __('Remove this expense?') }}">{{ __('Delete') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="p-6 text-center text-sm text-zinc-500">{{ __('No expenses on this day.') }}</div>
            @endforelse
        </div>
    </div>

    <flux:modal wire:model="showExpenseModal" class="w-full max-w-md">
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
                <flux:input type="number" step="0.01" min="0.01" inputmode="decimal" wire:model="expenseAmount" class:input="text-xl! font-semibold tabular-nums" />
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
