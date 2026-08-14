<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ServiceStatisticsService
{
    /**
     * Get services sold during the selected shift.
     *
     * @return Collection<int, Service>
     */
    public function servicesForShift(Shift $shift): Collection
    {
        return Service::query()
            ->select(['id', 'name'])
            ->whereIn('id', $this->invoiceItemsQuery($shift)->select('service_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Build operational and financial statistics for a service in a shift.
     *
     * @return array{
     *     total_visits: int,
     *     unique_patients: int,
     *     revenue: float,
     *     average_wait_minutes: ?int,
     *     statuses: array<string, int>,
     *     doctor_breakdown: list<array{doctor_name: string, visits: int, revenue: float}>
     * }
     */
    public function forShiftAndService(Shift $shift, Service $service): array
    {
        $invoiceItems = $this->invoiceItemsQuery($shift, $service);
        $totals = (clone $invoiceItems)
            ->selectRaw('COUNT(*) as total_visits, COALESCE(SUM(price), 0) as revenue')
            ->first();

        $tokenQuery = QueueToken::query()
            ->whereIn('invoice_item_id', $this->invoiceItemsQuery($shift, $service)->select('id'));

        $statusRows = (clone $tokenQuery)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->get();
        $statuses = [];

        foreach ($statusRows as $statusRow) {
            $statuses[(string) $statusRow->getAttribute('status')] = (int) $statusRow->getAttribute('total');
        }

        $waitTimes = (clone $tokenQuery)
            ->whereNotNull('arrived_at')
            ->whereNotNull('displayed_at')
            ->get(['arrived_at', 'displayed_at'])
            ->map(fn (QueueToken $token): int => (int) round($token->arrived_at->diffInMinutes($token->displayed_at, true)));

        $doctorRows = (clone $invoiceItems)
            ->selectRaw("COALESCE(doctor_name, 'Unassigned') as doctor_name, COUNT(*) as visits, COALESCE(SUM(price), 0) as revenue")
            ->groupBy('doctor_id', 'doctor_name')
            ->orderByDesc('visits')
            ->get();
        $doctorBreakdown = [];

        foreach ($doctorRows as $doctorRow) {
            $doctorBreakdown[] = [
                'doctor_name' => (string) $doctorRow->getAttribute('doctor_name'),
                'visits' => (int) $doctorRow->getAttribute('visits'),
                'revenue' => (float) $doctorRow->getAttribute('revenue'),
            ];
        }

        $uniquePatients = Invoice::query()
            ->where('shift_id', $shift->id)
            ->where('status', '!=', 'cancelled')
            ->whereHas('items', fn (Builder $query) => $query->where('service_id', $service->id))
            ->distinct()
            ->count('patient_id');

        return [
            'total_visits' => (int) ($totals?->getAttribute('total_visits') ?? 0),
            'unique_patients' => $uniquePatients,
            'revenue' => (float) ($totals?->getAttribute('revenue') ?? 0),
            'average_wait_minutes' => $waitTimes->isEmpty() ? null : (int) round($waitTimes->average()),
            'statuses' => $statuses,
            'doctor_breakdown' => $doctorBreakdown,
        ];
    }

    /**
     * Build the reusable invoice item query.
     *
     * @return Builder<InvoiceItem>
     */
    private function invoiceItemsQuery(Shift $shift, ?Service $service = null): Builder
    {
        $query = InvoiceItem::query();

        return $this->constrainInvoiceItems($query, $shift, $service);
    }

    /**
     * Apply shift, service, and cancellation constraints.
     *
     * @param  Builder<InvoiceItem>  $query
     * @return Builder<InvoiceItem>
     */
    private function constrainInvoiceItems(Builder $query, Shift $shift, ?Service $service = null): Builder
    {
        return $query
            ->when($service !== null, fn (Builder $serviceQuery) => $serviceQuery->where('service_id', $service->id))
            ->whereHas('invoice', function (Builder $invoiceQuery) use ($shift): void {
                $invoiceQuery
                    ->where('shift_id', $shift->id)
                    ->where('status', '!=', 'cancelled');
            });
    }
}
