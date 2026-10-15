<?php

namespace App\Services\Hermes;

use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Money, slips and patient counts per shift, using the same totals as the reception shift page.
 */
class ShiftSummaryReport
{
    private const EXCLUDED_INVOICE_STATUSES = ['cancelled', 'returned'];

    /**
     * @return array<string, mixed>
     */
    public function build(ReportPeriod $period): array
    {
        $shifts = $period->shifts->map(fn (Shift $shift) => $this->shiftSummary($shift))->values();
        $moneyKeys = ['walk_in_sales', 'lab_sales', 'procedure_payments', 'total_sales', 'cash_sales', 'online_sales', 'expenses', 'doctor_payouts'];
        $countKeys = ['opd_slips', 'lab_cases', 'returns', 'pending_approvals'];

        return [
            'period' => $period->toArray(),
            'shift_count' => $shifts->count(),
            'totals' => [
                'money' => collect($moneyKeys)->mapWithKeys(fn (string $key) => [$key => round($shifts->sum("money.{$key}"), 2)])->all(),
                'counts' => collect($countKeys)->mapWithKeys(fn (string $key) => [$key => $shifts->sum("counts.{$key}")])->all(),
                'services' => $this->servicesBilled($period->shiftIds()),
            ],
            'shifts' => $shifts->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftSummary(Shift $shift): array
    {
        $settlement = $shift->settlement;

        return [
            'id' => $shift->id,
            'period' => $shift->period()->label(),
            'business_date' => $shift->businessDate()->toDateString(),
            'opened_by' => $shift->user?->name,
            'status' => $shift->status,
            'opened_at' => $shift->opened_at->toDateTimeString(),
            'closed_at' => $shift->closed_at?->toDateTimeString(),
            'money' => [
                'opening_balance' => (float) $shift->opening_balance,
                'walk_in_sales' => $shift->totalWalkInSales(),
                'lab_sales' => $shift->totalLabSales(),
                'procedure_payments' => $shift->totalProcedureSales(),
                'total_sales' => $shift->totalSales(),
                'cash_sales' => $shift->totalCashSales(),
                'online_sales' => $shift->totalOnlineSales(),
                'expenses' => (float) $shift->totalExpenses(),
                'doctor_payouts' => (float) $shift->totalDailyPayouts(),
                'expected_cash' => $shift->expectedCash(),
                'declared_closing_balance' => $shift->closing_balance === null ? null : (float) $shift->closing_balance,
            ],
            'settlement' => $settlement === null ? null : [
                'received_amount' => $settlement->received_amount,
                'difference' => $settlement->difference,
                'is_short' => $settlement->isShort(),
                'settled_at' => $settlement->settled_at?->toDateTimeString(),
            ],
            'counts' => [
                'opd_slips' => $shift->invoices()->whereNotIn('status', self::EXCLUDED_INVOICE_STATUSES)->count(),
                'lab_cases' => $shift->labInvoices()->whereNotIn('status', self::EXCLUDED_INVOICE_STATUSES)->count(),
                'returns' => $shift->invoices()->where('status', 'returned')->count() + $shift->labInvoices()->where('status', 'returned')->count(),
                'pending_approvals' => $shift->pendingApprovalsCount(),
            ],
        ];
    }

    /**
     * OPD slips per service across the shifts, busiest first.
     *
     * @param  list<int>  $shiftIds
     * @return list<array{service: string, slips: int, amount: float}>
     */
    private function servicesBilled(array $shiftIds): array
    {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('services', 'services.id', '=', 'invoice_items.service_id')
            ->whereIn('invoices.shift_id', $shiftIds)
            ->whereNotIn('invoices.status', self::EXCLUDED_INVOICE_STATUSES)
            ->groupBy('invoice_items.service_id', 'services.name')
            ->selectRaw('COALESCE(services.name, MAX(invoice_items.service_name)) as service, COUNT(*) as slips, SUM(invoice_items.price) as amount')
            ->orderByDesc('slips')
            ->get()
            ->map(fn (object $row) => ['service' => trim((string) $row->service), 'slips' => (int) $row->slips, 'amount' => (float) $row->amount])
            ->all();
    }
}
