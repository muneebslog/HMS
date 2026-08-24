<?php

namespace App\Services;

use App\Models\Procedure;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProcedureFinanceReportService
{
    /**
     * Build billed, collected, and outstanding totals per procedure type for a date range.
     *
     * @return array{
     *     start: Carbon,
     *     end: Carbon,
     *     cases: int,
     *     billed: float,
     *     collected: float,
     *     outstanding: float,
     *     by_type: Collection<int, array{procedure_type_id: int|null, name: string, cases: int, billed: float, collected: float, outstanding: float}>,
     *     procedures: \Illuminate\Database\Eloquent\Collection<int, Procedure>
     * }
     */
    public function forDateRange(CarbonInterface $start, CarbonInterface $end): array
    {
        $rangeStart = $start->copy()->startOfDay();
        $rangeEnd = $end->copy()->endOfDay();

        $procedures = Procedure::query()
            ->with([
                'patient:id,name,mrn',
                'doctor:id,name',
                'procedureType:id,name',
            ])
            ->withSum('payments as paid_amount', 'amount')
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->latest()
            ->get();

        $byType = $procedures
            ->groupBy(fn (Procedure $procedure): string => $procedure->procedure_type_id === null
                ? 'name:'.$procedure->name
                : (string) $procedure->procedure_type_id)
            ->map(function (Collection $group): array {
                /** @var Procedure $procedure */
                $procedure = $group->first();
                $billed = (float) $group->sum('full_amount');
                $collected = (float) $group->sum(fn (Procedure $item): float => (float) ($item->paid_amount ?? 0));

                return [
                    'procedure_type_id' => $procedure->procedure_type_id,
                    'name' => $procedure->procedureType?->name ?? $procedure->name,
                    'cases' => $group->count(),
                    'billed' => $billed,
                    'collected' => $collected,
                    'outstanding' => $billed - $collected,
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $billed = (float) $procedures->sum('full_amount');
        $collected = (float) $procedures->sum(fn (Procedure $procedure): float => (float) ($procedure->paid_amount ?? 0));

        return [
            'start' => $rangeStart,
            'end' => $rangeEnd,
            'cases' => $procedures->count(),
            'billed' => $billed,
            'collected' => $collected,
            'outstanding' => $billed - $collected,
            'by_type' => $byType,
            'procedures' => $procedures,
        ];
    }
}
