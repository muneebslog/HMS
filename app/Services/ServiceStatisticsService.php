<?php

namespace App\Services;

use App\Models\InvoiceItem;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ServiceStatisticsService
{
    /**
     * Build daily usage statistics for a service in a date and time range.
     *
     * @return array{
     *     total: int,
     *     average_per_day: float,
     *     highest_usage: array{date: string, total: int},
     *     lowest_usage: array{date: string, total: int},
     *     daily_usage: list<array{date: string, total: int}>
     * }
     */
    public function forDateAndTimeRange(
        Service $service,
        Carbon $dateFrom,
        Carbon $dateTo,
        string $timeFrom,
        string $timeTo,
    ): array {
        $currentDate = $dateFrom->copy()->startOfDay();
        $dailyTotals = [$currentDate->toDateString() => 0];
        $timeFromWithSeconds = $timeFrom.':00';
        $timeToWithSeconds = $timeTo.':59';

        while ($currentDate->isBefore($dateTo)) {
            $currentDate->addDay();
            $dailyTotals[$currentDate->toDateString()] = 0;
        }

        $items = InvoiceItem::query()
            ->select(['id', 'invoice_id'])
            ->with('invoice:id,created_at')
            ->where('service_id', $service->id)
            ->whereHas('invoice', function (Builder $query) use ($dateFrom, $dateTo, $timeFrom, $timeFromWithSeconds, $timeTo, $timeToWithSeconds): void {
                $query
                    ->where('status', '!=', 'cancelled')
                    ->whereBetween('created_at', [
                        $dateFrom->copy()->startOfDay(),
                        $dateTo->copy()->endOfDay(),
                    ]);

                if ($timeFrom <= $timeTo) {
                    $query
                        ->whereTime('created_at', '>=', $timeFromWithSeconds)
                        ->whereTime('created_at', '<=', $timeToWithSeconds);

                    return;
                }

                $query->where(function (Builder $overnightQuery) use ($timeFromWithSeconds, $timeToWithSeconds): void {
                    $overnightQuery
                        ->whereTime('created_at', '>=', $timeFromWithSeconds)
                        ->orWhereTime('created_at', '<=', $timeToWithSeconds);
                });
            })
            ->get();

        foreach ($items as $item) {
            $date = $item->invoice->created_at->toDateString();
            $dailyTotals[$date]++;
        }

        $highestTotal = max($dailyTotals);
        $lowestTotal = min($dailyTotals);
        $highestDate = (string) array_search($highestTotal, $dailyTotals, true);
        $lowestDate = (string) array_search($lowestTotal, $dailyTotals, true);
        $dailyUsage = [];

        foreach ($dailyTotals as $date => $total) {
            $dailyUsage[] = ['date' => $date, 'total' => $total];
        }

        return [
            'total' => $items->count(),
            'average_per_day' => round($items->count() / count($dailyTotals), 1),
            'highest_usage' => ['date' => $highestDate, 'total' => $highestTotal],
            'lowest_usage' => ['date' => $lowestDate, 'total' => $lowestTotal],
            'daily_usage' => $dailyUsage,
        ];
    }
}
