@props([
    'shift',
    'showClosingBalance' => false,
])

@php
    /** @var \App\Models\Shift $shift */
    $openingBalance = $shift->opening_balance;
    $walkInSales = $shift->totalWalkInSales();
    $labSales = $shift->totalLabSales();
    $procedureSales = $shift->totalProcedureSales();
    $totalSales = $openingBalance + $shift->totalSales();
    $dailyPayouts = $shift->totalDailyPayouts();
    $expenses = $shift->totalExpenses();
    $cashToReceive = $shift->expectedCash();
@endphp

<div {{ $attributes->merge(['class' => 'mx-auto w-full max-w-md font-mono text-sm']) }}>
    <div class="space-y-2">
        <div class="flex items-baseline justify-between gap-4">
            <span class="text-zinc-600 dark:text-zinc-400">+ {{ __('Opening Balance') }}</span>
            <span class="shrink-0 font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{{ number_format($openingBalance, 2) }}</span>
        </div>

        <div class="flex items-baseline justify-between gap-4">
            <span class="text-zinc-600 dark:text-zinc-400">
                + {{ __('Walk-in Sales') }}
                <span class="text-zinc-400 dark:text-zinc-500">({{ __('cash') }})</span>
            </span>
            <span class="shrink-0 font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{{ number_format($walkInSales, 2) }}</span>
        </div>

        <div class="flex items-baseline justify-between gap-4">
            <span class="text-zinc-600 dark:text-zinc-400">
                + {{ __('Lab Sales') }}
                <span class="text-zinc-400 dark:text-zinc-500">({{ __('cash') }})</span>
            </span>
            <span class="shrink-0 font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{{ number_format($labSales, 2) }}</span>
        </div>

        <div class="flex items-baseline justify-between gap-4">
            <span class="text-zinc-600 dark:text-zinc-400">
                + {{ __('Procedure Payments') }}
                <span class="text-zinc-400 dark:text-zinc-500">({{ __('cash') }})</span>
            </span>
            <span class="shrink-0 font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{{ number_format($procedureSales, 2) }}</span>
        </div>
    </div>

    <div class="my-3 border-t border-dashed border-zinc-300 dark:border-zinc-600"></div>

    <div class="flex items-baseline justify-between gap-4">
        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Total Sales') }}</span>
        <span class="shrink-0 font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">{{ number_format($totalSales, 2) }}</span>
    </div>

    <div class="my-3 border-t border-zinc-300 dark:border-zinc-600"></div>

    <div class="space-y-2">
        <div class="flex items-baseline justify-between gap-4">
            <span class="text-zinc-600 dark:text-zinc-400">- {{ __('Daily Payouts') }}</span>
            <span class="shrink-0 font-medium tabular-nums text-red-600 dark:text-red-400">{{ number_format($dailyPayouts, 2) }}</span>
        </div>

        <div class="flex items-baseline justify-between gap-4">
            <span class="text-zinc-600 dark:text-zinc-400">- {{ __('Expenses') }}</span>
            <span class="shrink-0 font-medium tabular-nums text-red-600 dark:text-red-400">{{ number_format($expenses, 2) }}</span>
        </div>
    </div>

    <div class="my-3 border-t-2 border-zinc-400 dark:border-zinc-500"></div>

    <div class="flex items-baseline justify-between gap-4">
        <span class="text-base font-bold uppercase tracking-wide text-zinc-900 dark:text-zinc-100">{{ __('Cash to Receive') }}</span>
        <span class="shrink-0 text-base font-bold tabular-nums text-zinc-900 dark:text-zinc-100">{{ number_format($cashToReceive, 2) }}</span>
    </div>

    @if ($showClosingBalance && $shift->closing_balance !== null)
        <div class="mt-3 flex items-baseline justify-between gap-4 border-t border-dashed border-zinc-300 pt-3 dark:border-zinc-600">
            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Closing Balance') }}</span>
            <span class="shrink-0 font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{{ number_format($shift->closing_balance, 2) }}</span>
        </div>
    @endif
</div>
