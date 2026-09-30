<?php

use App\Enums\FinanceShiftPeriod;
use App\Models\AppSetting;
use App\Models\FinanceExpense;
use App\Models\ShiftSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Finance')] class extends Component
{
    public const SessionKey = 'finance_owner_access';

    public const MaxAttemptsPerMinute = 5;

    public string $password = '';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->to = now()->toDateString();
        $this->from = now()->subDays(6)->toDateString();
    }

    /**
     * Whether the visitor has entered the current password in this browser session.
     */
    #[Computed]
    public function unlocked(): bool
    {
        $hash = AppSetting::financeOwnerPasswordHash();

        return $hash !== null && hash_equals(hash('sha256', $hash), (string) session(self::SessionKey));
    }

    #[Computed]
    public function isEnabled(): bool
    {
        return AppSetting::financeOwnerPasswordHash() !== null;
    }

    public function unlock(): void
    {
        $this->validate(['password' => ['required', 'string', 'max:255']], [], ['password' => __('password')]);

        $throttleKey = 'finance-owner:'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MaxAttemptsPerMinute)) {
            $this->addError('password', __('Too many tries. Please wait :seconds seconds.', ['seconds' => RateLimiter::availableIn($throttleKey)]));

            return;
        }

        $hash = AppSetting::financeOwnerPasswordHash();

        if ($hash === null || ! Hash::check($this->password, $hash)) {
            RateLimiter::hit($throttleKey, 60);
            $this->addError('password', __('Wrong password. Please try again.'));
            $this->password = '';

            return;
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();
        session([self::SessionKey => hash('sha256', $hash)]);

        $this->password = '';
        unset($this->unlocked);
    }

    public function lock(): void
    {
        session()->forget(self::SessionKey);
        unset($this->unlocked);
    }

    /**
     * Quick date range shortcuts.
     */
    public function setRange(string $range): void
    {
        [$from, $to] = match ($range) {
            'week' => [now()->subDays(6), now()],
            'month' => [now()->startOfMonth(), now()],
            'last-month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            default => [Carbon::parse($this->from), Carbon::parse($this->to)],
        };

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function updatedFrom(): void
    {
        $this->normaliseRange();
    }

    public function updatedTo(): void
    {
        $this->normaliseRange();
    }

    /**
     * Totals for the current month.
     *
     * @return array{label: string, received: float, expenses: float, net: float}
     */
    #[Computed]
    public function month(): array
    {
        abort_unless($this->unlocked, 403);

        $received = (float) ShiftSettlement::query()
            ->whereYear('business_date', now()->year)
            ->whereMonth('business_date', now()->month)
            ->sum('received_amount');

        $expenses = (float) FinanceExpense::query()
            ->whereYear('expense_date', now()->year)
            ->whereMonth('expense_date', now()->month)
            ->sum('amount');

        return [
            'label' => now()->format('F Y'),
            'received' => $received,
            'expenses' => $expenses,
            'net' => $received - $expenses,
        ];
    }

    /**
     * One entry per day in the picked range, newest first.
     *
     * @return Collection<int, array{date: Carbon, night: ?float, morning: ?float, evening: ?float, received: float, expenses: Collection<int, FinanceExpense>, expenses_total: float, net: float}>
     */
    #[Computed]
    public function ledger(): Collection
    {
        abort_unless($this->unlocked, 403);

        $settlements = ShiftSettlement::query()
            ->whereDate('business_date', '>=', $this->from)
            ->whereDate('business_date', '<=', $this->to)
            ->get()
            ->groupBy(fn (ShiftSettlement $settlement) => $settlement->business_date->toDateString());

        $expenses = FinanceExpense::query()
            ->whereDate('expense_date', '>=', $this->from)
            ->whereDate('expense_date', '<=', $this->to)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (FinanceExpense $expense) => $expense->expense_date->toDateString());

        $days = collect();

        for ($day = Carbon::parse($this->to); $day->gte(Carbon::parse($this->from)); $day = $day->copy()->subDay()) {
            $key = $day->toDateString();
            $daySettlements = $settlements->get($key, collect());
            $dayExpenses = $expenses->get($key, collect());

            $periodTotal = function (FinanceShiftPeriod $period) use ($daySettlements): ?float {
                $matching = $daySettlements->filter(fn (ShiftSettlement $settlement) => $settlement->period === $period);

                return $matching->isEmpty() ? null : (float) $matching->sum('received_amount');
            };

            $received = (float) $daySettlements->sum('received_amount');
            $expensesTotal = (float) $dayExpenses->sum('amount');

            $days->push([
                'date' => $day->copy(),
                'night' => $periodTotal(FinanceShiftPeriod::Night),
                'morning' => $periodTotal(FinanceShiftPeriod::Morning),
                'evening' => $periodTotal(FinanceShiftPeriod::Evening),
                'received' => $received,
                'expenses' => $dayExpenses,
                'expenses_total' => $expensesTotal,
                'net' => $received - $expensesTotal,
            ]);
        }

        return $days;
    }

    /**
     * @return array{received: float, expenses: float, net: float}
     */
    #[Computed]
    public function rangeTotals(): array
    {
        $received = (float) $this->ledger->sum('received');
        $expenses = (float) $this->ledger->sum('expenses_total');

        return ['received' => $received, 'expenses' => $expenses, 'net' => $received - $expenses];
    }

    /**
     * Keep the range valid and at most one year long.
     */
    private function normaliseRange(): void
    {
        $from = strtotime($this->from) !== false ? Carbon::parse($this->from) : now()->subDays(6);
        $to = strtotime($this->to) !== false ? Carbon::parse($this->to) : now();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }
}; ?>

<div class="mx-auto flex min-h-screen w-full max-w-md flex-col gap-4 px-4 py-6">
    @php($money = fn (?float $value): string => number_format((float) $value, fmod((float) $value, 1.0) == 0.0 ? 0 : 2))

    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <x-app-logo-icon class="size-8 fill-current text-zinc-900 dark:text-white" />
            <div>
                <div class="text-lg font-bold leading-tight text-zinc-900 dark:text-white">{{ config('hospital.name') }}</div>
                <div class="text-xs text-zinc-500">{{ __('Finance') }}</div>
            </div>
        </div>
        @if ($this->unlocked)
            <flux:button size="sm" variant="ghost" icon="lock-closed" wire:click="lock">{{ __('Lock') }}</flux:button>
        @endif
    </div>

    @if (! $this->unlocked)
        <div class="mt-10 rounded-3xl bg-white p-6 shadow-sm dark:bg-zinc-900">
            <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                <flux:icon name="lock-closed" class="size-7 text-zinc-600 dark:text-zinc-300" />
            </div>

            @if ($this->isEnabled)
                <h1 class="text-center text-xl font-bold text-zinc-900 dark:text-white">{{ __('Enter password') }}</h1>
                <p class="mt-1 text-center text-sm text-zinc-500">{{ __('Type the password you were given to see the finance report.') }}</p>

                <form wire:submit="unlock" class="mt-6 space-y-4">
                    <flux:input
                        type="password"
                        wire:model="password"
                        autofocus
                        autocomplete="current-password"
                        :placeholder="__('Password')"
                        :aria-label="__('Password')"
                        class:input="h-14! text-center text-xl!"
                    />
                    <flux:error name="password" />

                    <flux:button type="submit" variant="primary" class="h-14! w-full text-lg!">
                        {{ __('Open') }}
                    </flux:button>
                </form>
            @else
                <h1 class="text-center text-xl font-bold text-zinc-900 dark:text-white">{{ __('Not available') }}</h1>
                <p class="mt-1 text-center text-sm text-zinc-500">{{ __('This page has not been switched on yet.') }}</p>
            @endif
        </div>
    @else
        @php($month = $this->month)
        @php($totals = $this->rangeTotals)

        {{-- This month --}}
        <div class="rounded-3xl bg-zinc-900 p-5 text-white shadow-sm dark:bg-zinc-800">
            <div class="text-sm text-zinc-300">{{ __('This month') }} · {{ $month['label'] }}</div>
            <div class="mt-3 text-xs uppercase tracking-wide text-zinc-400">{{ __('Cash in hand') }}</div>
            <div class="text-5xl font-bold tabular-nums">{{ $money($month['net']) }}</div>
            <div class="mt-4 grid grid-cols-2 gap-2">
                <div class="rounded-2xl bg-white/10 p-3">
                    <div class="text-xs text-zinc-300">{{ __('Money in') }}</div>
                    <div class="text-lg font-semibold tabular-nums text-green-300">{{ $money($month['received']) }}</div>
                </div>
                <div class="rounded-2xl bg-white/10 p-3">
                    <div class="text-xs text-zinc-300">{{ __('Money out') }}</div>
                    <div class="text-lg font-semibold tabular-nums text-red-300">{{ $money($month['expenses']) }}</div>
                </div>
            </div>
        </div>

        {{-- Date range --}}
        <div class="rounded-3xl bg-white p-4 shadow-sm dark:bg-zinc-900">
            <div class="grid grid-cols-3 gap-2">
                @foreach (['week' => __('Last 7 days'), 'month' => __('This month'), 'last-month' => __('Last month')] as $range => $label)
                    <flux:button size="sm" wire:click="setRange('{{ $range }}')">{{ $label }}</flux:button>
                @endforeach
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <flux:field>
                    <flux:label>{{ __('From') }}</flux:label>
                    <flux:input type="date" wire:model.live="from" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('To') }}</flux:label>
                    <flux:input type="date" wire:model.live="to" />
                </flux:field>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-2 border-t border-zinc-100 pt-3 text-center dark:border-zinc-800">
                <div>
                    <div class="text-xs text-zinc-500">{{ __('In') }}</div>
                    <div class="font-semibold tabular-nums text-green-700 dark:text-green-400">{{ $money($totals['received']) }}</div>
                </div>
                <div>
                    <div class="text-xs text-zinc-500">{{ __('Out') }}</div>
                    <div class="font-semibold tabular-nums text-red-700 dark:text-red-400">{{ $money($totals['expenses']) }}</div>
                </div>
                <div>
                    <div class="text-xs text-zinc-500">{{ __('Left') }}</div>
                    <div class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $money($totals['net']) }}</div>
                </div>
            </div>
        </div>

        {{-- Ledger --}}
        <div class="space-y-3" wire:loading.class="opacity-50">
            @foreach ($this->ledger as $day)
                <div wire:key="day-{{ $day['date']->toDateString() }}" class="overflow-hidden rounded-3xl bg-white shadow-sm dark:bg-zinc-900">
                    <div class="flex items-center justify-between bg-zinc-50 px-4 py-3 dark:bg-zinc-800/60">
                        <div class="font-semibold text-zinc-900 dark:text-white">
                            {{ $day['date']->format('D, j M') }}
                            @if ($day['date']->isToday())
                                <span class="ms-1 text-xs font-normal text-zinc-500">{{ __('Today') }}</span>
                            @endif
                        </div>
                        <div class="text-sm font-semibold tabular-nums {{ $day['net'] < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ $money($day['net']) }}</div>
                    </div>

                    <div class="divide-y divide-zinc-100 px-4 dark:divide-zinc-800">
                        @foreach (['night' => ['moon', __('Night in')], 'morning' => ['sun', __('Morning in')], 'evening' => ['cloud', __('Evening in')]] as $key => [$icon, $label])
                            <div class="flex items-center justify-between py-2.5">
                                <span class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                                    <flux:icon :name="$icon" variant="mini" class="text-zinc-400" />
                                    {{ $label }}
                                </span>
                                @if ($day[$key] === null)
                                    <span class="text-sm text-zinc-400">{{ __('not yet') }}</span>
                                @else
                                    <span class="font-semibold tabular-nums text-green-700 dark:text-green-400">+ {{ $money($day[$key]) }}</span>
                                @endif
                            </div>
                        @endforeach

                        @foreach ($day['expenses'] as $expense)
                            <div wire:key="expense-{{ $expense->id }}" class="flex items-center justify-between gap-3 py-2.5">
                                <span class="flex min-w-0 items-center gap-2 text-zinc-600 dark:text-zinc-300">
                                    <flux:icon name="arrow-up-right" variant="mini" class="shrink-0 text-red-400" />
                                    <span class="truncate">{{ $expense->name }}</span>
                                </span>
                                <span class="shrink-0 font-semibold tabular-nums text-red-700 dark:text-red-400">− {{ $money($expense->amount) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
