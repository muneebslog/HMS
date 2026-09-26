<?php

namespace App\Services;

use App\Enums\DripLineStatus;
use App\Enums\MedicationOrderStatus;
use App\Models\DripCharge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MedicationOrder;
use App\Models\MedicationOrderDrip;
use App\Models\MedicationOrderInjection;
use App\Models\MedicationOrderMedicine;
use App\Models\Service;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class MedicationDeliverySummaryService
{
    private const INVOICE_STATUS_CANCELLED = 'cancelled';

    private const INVOICE_STATUS_RETURNED = 'returned';

    /**
     * Summarise reception slips and medication orders for a date range.
     *
     * @return array{
     *     slips: list<array{service_id: int|null, name: string, is_drip: bool, made: int, returned: int}>,
     *     drips: array{slips: int, returned: int, without_order: int, orders: int, ordered: int, started: int, done: int, left: int},
     *     injections: array{ordered: int, given: int, left: int},
     *     medicines: array{ordered: int, given: int, left: int}
     * }
     */
    public function forDateRange(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->build(
            fn (Builder $query) => $query->whereBetween('invoices.created_at', [$from, $to]),
            $from,
            $to,
        );
    }

    /**
     * Summarise reception slips billed in a shift and medication orders written while it was open.
     *
     * @return array{
     *     slips: list<array{service_id: int|null, name: string, is_drip: bool, made: int, returned: int}>,
     *     drips: array{slips: int, returned: int, without_order: int, orders: int, ordered: int, started: int, done: int, left: int},
     *     injections: array{ordered: int, given: int, left: int},
     *     medicines: array{ordered: int, given: int, left: int}
     * }
     */
    public function forShift(Shift $shift): array
    {
        return $this->build(
            fn (Builder $query) => $query->where('invoices.shift_id', $shift->id),
            $shift->opened_at,
            $shift->closed_at ?? now(),
        );
    }

    /**
     * @param  Closure(Builder): mixed  $scopeInvoices
     * @return array{
     *     slips: list<array{service_id: int|null, name: string, is_drip: bool, made: int, returned: int}>,
     *     drips: array{slips: int, returned: int, without_order: int, orders: int, ordered: int, started: int, done: int, left: int},
     *     injections: array{ordered: int, given: int, left: int},
     *     medicines: array{ordered: int, given: int, left: int}
     * }
     */
    private function build(Closure $scopeInvoices, CarbonInterface $from, CarbonInterface $to): array
    {
        $slips = $this->slips($scopeInvoices);
        $dripSlips = array_values(array_filter($slips, fn (array $slip): bool => $slip['is_drip']));
        $dripSlipsMade = array_sum(array_column($dripSlips, 'made'));
        $dripSlipsReturned = array_sum(array_column($dripSlips, 'returned'));
        $dripSlipsWithOrder = $this->dripSlipsWithOrder($scopeInvoices);
        $drips = $this->drips($from, $to);

        return [
            'slips' => $slips,
            'drips' => [
                'slips' => $dripSlipsMade,
                'returned' => $dripSlipsReturned,
                'without_order' => max(0, $dripSlipsMade - $dripSlipsReturned - $dripSlipsWithOrder),
                ...$drips,
            ],
            'injections' => $this->deliveredLines(MedicationOrderInjection::class, $from, $to),
            'medicines' => $this->deliveredLines(MedicationOrderMedicine::class, $from, $to),
        ];
    }

    /**
     * Count slips per service, busiest first with drip services on top.
     *
     * @param  Closure(Builder): mixed  $scopeInvoices
     * @return list<array{service_id: int|null, name: string, is_drip: bool, made: int, returned: int}>
     */
    private function slips(Closure $scopeInvoices): array
    {
        $items = $this->table(InvoiceItem::class);
        $invoices = $this->table(Invoice::class);
        $services = $this->table(Service::class);

        $query = DB::table($items)
            ->join($invoices, "{$invoices}.id", '=', "{$items}.invoice_id")
            ->leftJoin($services, "{$services}.id", '=', "{$items}.service_id")
            ->where("{$invoices}.status", '!=', self::INVOICE_STATUS_CANCELLED)
            ->groupBy("{$items}.service_id", "{$services}.name", "{$services}.is_drip")
            ->select("{$items}.service_id", "{$services}.name as service_name", "{$services}.is_drip")
            ->selectRaw("MAX({$items}.service_name) as item_name")
            ->selectRaw('COUNT(*) as made')
            ->selectRaw("SUM(CASE WHEN {$invoices}.status = ? THEN 1 ELSE 0 END) as returned", [self::INVOICE_STATUS_RETURNED]);

        $scopeInvoices($query);

        return $query->get()
            ->map(fn (object $row): array => [
                'service_id' => $row->service_id === null ? null : (int) $row->service_id,
                'name' => trim((string) ($row->service_name ?? $row->item_name)),
                'is_drip' => (bool) $row->is_drip,
                'made' => (int) $row->made,
                'returned' => (int) $row->returned,
            ])
            ->sortBy([
                fn (array $a, array $b): int => $b['is_drip'] <=> $a['is_drip'],
                fn (array $a, array $b): int => $b['made'] <=> $a['made'],
                fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']),
            ])
            ->values()
            ->all();
    }

    /**
     * Count non-returned drip slips that were billed from a doctor's drip order.
     *
     * @param  Closure(Builder): mixed  $scopeInvoices
     */
    private function dripSlipsWithOrder(Closure $scopeInvoices): int
    {
        $items = $this->table(InvoiceItem::class);
        $invoices = $this->table(Invoice::class);
        $services = $this->table(Service::class);
        $charges = $this->table(DripCharge::class);

        $query = DB::table($items)
            ->join($invoices, "{$invoices}.id", '=', "{$items}.invoice_id")
            ->join($services, "{$services}.id", '=', "{$items}.service_id")
            ->where("{$services}.is_drip", true)
            ->whereNotIn("{$invoices}.status", [self::INVOICE_STATUS_CANCELLED, self::INVOICE_STATUS_RETURNED])
            ->whereExists(function (Builder $exists) use ($charges, $items): void {
                $exists->select(DB::raw(1))
                    ->from($charges)
                    ->whereColumn("{$charges}.invoice_id", "{$items}.invoice_id")
                    ->whereNotNull("{$charges}.medication_order_id");
            });

        $scopeInvoices($query);

        return $query->count();
    }

    /**
     * @return array{orders: int, ordered: int, started: int, done: int, left: int}
     */
    private function drips(CarbonInterface $from, CarbonInterface $to): array
    {
        $drips = $this->table(MedicationOrderDrip::class);

        $row = $this->orderLinesQuery($drips, $from, $to)
            ->where("{$drips}.status", '!=', DripLineStatus::Cancelled->value)
            ->selectRaw('COUNT(*) as ordered')
            ->selectRaw("COUNT(DISTINCT {$drips}.medication_order_id) as orders")
            ->selectRaw("SUM(CASE WHEN {$drips}.started_at IS NOT NULL THEN 1 ELSE 0 END) as started")
            ->selectRaw("SUM(CASE WHEN {$drips}.done_at IS NOT NULL THEN 1 ELSE 0 END) as done")
            ->first();

        $ordered = (int) $row->ordered;
        $done = (int) $row->done;

        return [
            'orders' => (int) $row->orders,
            'ordered' => $ordered,
            'started' => (int) $row->started,
            'done' => $done,
            'left' => max(0, $ordered - $done),
        ];
    }

    /**
     * @param  class-string<MedicationOrderInjection|MedicationOrderMedicine>  $model
     * @return array{ordered: int, given: int, left: int}
     */
    private function deliveredLines(string $model, CarbonInterface $from, CarbonInterface $to): array
    {
        $lines = $this->table($model);

        $row = $this->orderLinesQuery($lines, $from, $to)
            ->selectRaw('COUNT(*) as ordered')
            ->selectRaw("SUM(CASE WHEN {$lines}.delivered_at IS NOT NULL THEN 1 ELSE 0 END) as given")
            ->first();

        $ordered = (int) $row->ordered;
        $given = (int) $row->given;

        return [
            'ordered' => $ordered,
            'given' => $given,
            'left' => max(0, $ordered - $given),
        ];
    }

    private function orderLinesQuery(string $lines, CarbonInterface $from, CarbonInterface $to): Builder
    {
        $orders = $this->table(MedicationOrder::class);

        return DB::table($lines)
            ->join($orders, "{$orders}.id", '=', "{$lines}.medication_order_id")
            ->where("{$orders}.status", '!=', MedicationOrderStatus::Draft->value)
            ->whereBetween("{$orders}.created_at", [$from, $to]);
    }

    /**
     * @param  class-string  $model
     */
    private function table(string $model): string
    {
        return (new $model)->getTable();
    }
}
