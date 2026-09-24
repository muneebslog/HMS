@props([
    'shift',
    'showClosingBalance' => false,
])

@php
    /** @var \App\Models\Shift $shift */
    $openingBalance = $shift->opening_balance;
    $cash = \App\Enums\PaymentMode::Cash;
    $online = \App\Enums\PaymentMode::Online;
    $walkInSales = $shift->totalWalkInSales($cash);
    $labSales = $shift->totalLabSales($cash);
    $procedureSales = $shift->totalProcedureSales($cash);
    $totalSales = $openingBalance + $shift->totalCashSales();
    $onlineWalkIn = $shift->totalWalkInSales($online);
    $onlineLab = $shift->totalLabSales($online);
    $onlineProcedures = $shift->totalProcedureSales($online);
    $onlineTotal = $onlineWalkIn + $onlineLab + $onlineProcedures;
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

    <div class="mt-5 rounded-lg border border-sky-200 bg-sky-50 p-3 dark:border-sky-900 dark:bg-sky-950/40">
        <div class="flex items-baseline justify-between gap-4">
            <span class="font-semibold text-sky-800 dark:text-sky-200">{{ __('Online Payments') }}</span>
            <span class="shrink-0 font-semibold tabular-nums text-sky-800 dark:text-sky-200">{{ number_format($onlineTotal, 2) }}</span>
        </div>
        <div class="mt-2 space-y-1 text-xs text-sky-700 dark:text-sky-300">
            <div class="flex justify-between gap-4"><span>{{ __('Walk-in') }}</span><span class="tabular-nums">{{ number_format($onlineWalkIn, 2) }}</span></div>
            <div class="flex justify-between gap-4"><span>{{ __('Lab') }}</span><span class="tabular-nums">{{ number_format($onlineLab, 2) }}</span></div>
            <div class="flex justify-between gap-4"><span>{{ __('Procedures') }}</span><span class="tabular-nums">{{ number_format($onlineProcedures, 2) }}</span></div>
        </div>
        <div class="mt-2 text-[11px] text-sky-600 dark:text-sky-400">{{ __('Not in the drawer, so not part of Cash to Receive.') }}</div>
    </div>

    <div class="mt-3 flex items-baseline justify-between gap-4">
        <span class="text-zinc-600 dark:text-zinc-400">{{ __('All Sales (cash + online)') }}</span>
        <span class="shrink-0 font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{{ number_format($shift->totalSales(), 2) }}</span>
    </div>

    @if ($showClosingBalance && $shift->closing_balance !== null)
        <div class="mt-3 flex items-baseline justify-between gap-4 border-t border-dashed border-zinc-300 pt-3 dark:border-zinc-600">
            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Closing Balance') }}</span>
            <span class="shrink-0 font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{{ number_format($shift->closing_balance, 2) }}</span>
        </div>
    @endif
</div>
