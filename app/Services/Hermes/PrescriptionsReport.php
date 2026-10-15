<?php

namespace App\Services\Hermes;

use App\Enums\MedicationOrderStatus;
use App\Models\MedicationOrder;
use App\Models\MedicationOrderInjection;
use App\Models\MedicationOrderMedicine;
use App\Models\QueueToken;
use App\Services\MedicationDeliverySummaryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Prescription coverage for services where the doctor writes a prescription (e.g. General Checkup):
 * how many patients came, how many got a prescription in the HMS, and prescriptions entered late.
 */
class PrescriptionsReport
{
    private const EXCLUDED_INVOICE_STATUSES = ['cancelled', 'returned'];

    public function __construct(private MedicationDeliverySummaryService $deliveries) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ReportPeriod $period, int $detailLimit = 15): array
    {
        $tokens = $this->tokens($period);
        $withOrder = $tokens->filter(fn (QueueToken $token) => $token->medicationOrders->isNotEmpty());
        $delays = $withOrder
            ->map(fn (QueueToken $token) => (int) $token->created_at->diffInMinutes($token->medicationOrders->first()->created_at))
            ->sort()
            ->values();
        $enteredAfterShift = $withOrder->filter(fn (QueueToken $token) => $this->enteredAfterShiftClosed($token));
        $withoutOrder = $tokens->reject(fn (QueueToken $token) => $token->medicationOrders->isNotEmpty());
        $delivery = $period->shifts->count() === 1
            ? $this->deliveries->forShift($period->shifts->first())
            : $this->deliveries->forDateRange($period->from, $period->to);

        return [
            'period' => $period->toArray(),
            'totals' => [
                'patients' => $tokens->count(),
                'with_prescription' => $withOrder->count(),
                'without_prescription' => $withoutOrder->count(),
                'coverage_percent' => $tokens->isEmpty() ? null : round($withOrder->count() / $tokens->count() * 100, 1),
            ],
            'by_service' => $tokens
                ->groupBy(fn (QueueToken $token) => $token->serviceQueue->service->name)
                ->map(fn (Collection $serviceTokens, string $service) => [
                    'service' => $service,
                    'patients' => $serviceTokens->count(),
                    'with_prescription' => $serviceTokens->filter(fn (QueueToken $token) => $token->medicationOrders->isNotEmpty())->count(),
                ])
                ->values()
                ->all(),
            'entry_delay_minutes' => [
                'median' => $delays->isEmpty() ? null : $delays[intdiv($delays->count() - 1, 2)],
                'max' => $delays->max(),
            ],
            'entered_after_shift_closed' => [
                'count' => $enteredAfterShift->count(),
                'patients' => $enteredAfterShift->take($detailLimit)->map(fn (QueueToken $token) => [
                    ...$this->tokenSummary($token),
                    'prescribed_at' => $token->medicationOrders->first()->created_at->toDateTimeString(),
                    'delay_minutes' => (int) $token->created_at->diffInMinutes($token->medicationOrders->first()->created_at),
                ])->values()->all(),
            ],
            'entered_this_period_for_earlier_patients' => $this->ordersForEarlierTokens($period),
            'given_this_period_from_earlier_prescriptions' => [
                'medicines' => $this->linesGivenFromEarlierOrders(MedicationOrderMedicine::class, $period),
                'injections' => $this->linesGivenFromEarlierOrders(MedicationOrderInjection::class, $period),
            ],
            'without_prescription_latest' => $withoutOrder->sortByDesc('created_at')->take($detailLimit)
                ->map(fn (QueueToken $token) => $this->tokenSummary($token))->values()->all(),
            'medication_delivery' => [
                'medicines' => $delivery['medicines'],
                'injections' => $delivery['injections'],
                'drips' => $delivery['drips'],
            ],
        ];
    }

    /**
     * Arrived tokens of prescription services in the period's shifts, excluding returned slips.
     *
     * @return Collection<int, QueueToken>
     */
    private function tokens(ReportPeriod $period): Collection
    {
        return $this->prescriptionTokens()
            ->whereHas('serviceQueue', fn (Builder $queue) => $queue->whereIn('shift_id', $period->shiftIds()))
            ->with(['serviceQueue.service', 'serviceQueue.shift', 'patient', 'medicationOrders' => fn ($orders) => $orders->oldest()])
            ->oldest()
            ->get();
    }

    /**
     * @return Builder<QueueToken>
     */
    private function prescriptionTokens(): Builder
    {
        return QueueToken::query()
            ->where('status', '!=', 'reserved')
            ->whereHas('serviceQueue.service', fn (Builder $service) => $service->where('needs_medication', true))
            ->where(fn (Builder $query) => $query
                ->whereNull('invoice_item_id')
                ->orWhereHas('invoiceItem.invoice', fn (Builder $invoice) => $invoice->whereNotIn('status', self::EXCLUDED_INVOICE_STATUSES)));
    }

    private function enteredAfterShiftClosed(QueueToken $token): bool
    {
        $closedAt = $token->serviceQueue->shift?->closed_at;

        return $closedAt !== null && $token->medicationOrders->first()->created_at->gt($closedAt);
    }

    /**
     * Prescriptions written during this period for patients whose token belongs to an earlier shift.
     *
     * @return array{count: int}
     */
    private function ordersForEarlierTokens(ReportPeriod $period): array
    {
        $count = MedicationOrder::query()
            ->whereBetween('created_at', [$period->from, $period->to])
            ->whereHas('queueToken', fn (Builder $token) => $token->whereHas('serviceQueue', fn (Builder $queue) => $queue->whereNotIn('shift_id', $period->shiftIds())))
            ->whereRaw('id = (select min(first_orders.id) from medication_orders as first_orders where first_orders.queue_token_id = medication_orders.queue_token_id)')
            ->count();

        return ['count' => $count];
    }

    /**
     * Medicine or injection lines handed out in this period from prescriptions written before it.
     *
     * @param  class-string<MedicationOrderMedicine|MedicationOrderInjection>  $model
     */
    private function linesGivenFromEarlierOrders(string $model, ReportPeriod $period): int
    {
        return $model::query()
            ->whereBetween('delivered_at', [$period->from, $period->to])
            ->whereHas('medicationOrder', fn (Builder $order) => $order
                ->where('status', '!=', MedicationOrderStatus::Draft->value)
                ->where('created_at', '<', $period->from))
            ->count();
    }

    /**
     * @return array{patient_mrn: ?string, service: string, token: int, token_at: string}
     */
    private function tokenSummary(QueueToken $token): array
    {
        return [
            'patient_mrn' => PatientReference::of($token->patient),
            'service' => $token->serviceQueue->service->name,
            'token' => (int) $token->token_number,
            'token_at' => $token->created_at->toDateTimeString(),
        ];
    }
}
