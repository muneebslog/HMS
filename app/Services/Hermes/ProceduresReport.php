<?php

namespace App\Services\Hermes;

use App\Models\Procedure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Procedures (admissions, operations, deliveries) added and moved along during a period, plus who is on the ward.
 */
class ProceduresReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(ReportPeriod $period, int $detailLimit = 25): array
    {
        $added = $this->query()->whereBetween('created_at', [$period->from, $period->to])->oldest()->get();
        $onWard = $this->query()->onWard()->oldest('admitted_at')->get();

        return [
            'period' => $period->toArray(),
            'counts' => [
                'added' => $added->count(),
                'admitted' => Procedure::query()->whereBetween('admitted_at', [$period->from, $period->to])->count(),
                'operations_completed' => Procedure::query()->whereBetween('operation_completed_at', [$period->from, $period->to])->count(),
                'discharged' => Procedure::query()->whereBetween('discharged_at', [$period->from, $period->to])->count(),
                'on_ward_now' => $onWard->count(),
            ],
            'added_by_type' => $added
                ->groupBy(fn (Procedure $procedure) => $this->typeName($procedure))
                ->map(fn (Collection $procedures, string $type) => ['type' => $type, 'count' => $procedures->count()])
                ->values()
                ->all(),
            'added' => $added->take($detailLimit)->map(fn (Procedure $procedure) => $this->summary($procedure))->values()->all(),
            'on_ward' => $onWard->take($detailLimit)->map(fn (Procedure $procedure) => $this->summary($procedure))->values()->all(),
        ];
    }

    /**
     * @return Builder<Procedure>
     */
    private function query(): Builder
    {
        return Procedure::query()
            ->with(['patient', 'procedureType', 'doctor', 'room'])
            ->withSum('activePayments as paid_amount', 'amount');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Procedure $procedure): array
    {
        $paid = (float) ($procedure->paid_amount ?? 0);

        return [
            'id' => $procedure->id,
            'type' => $this->typeName($procedure),
            'patient_mrn' => PatientReference::of($procedure->patient),
            'doctor' => $procedure->doctor?->name,
            'status' => $procedure->status->label(),
            'stage' => $this->stage($procedure),
            'room' => $procedure->room?->number ?? $procedure->room_number,
            'added_at' => $procedure->created_at->toDateTimeString(),
            'admitted_at' => $procedure->admitted_at?->toDateTimeString(),
            'discharged_at' => $procedure->discharged_at?->toDateTimeString(),
            'full_amount' => (float) $procedure->full_amount,
            'paid' => $paid,
            'balance' => (float) $procedure->full_amount - $paid,
        ];
    }

    private function typeName(Procedure $procedure): string
    {
        return $procedure->procedureType?->name ?? $procedure->name ?? 'Unknown';
    }

    /**
     * The furthest step the procedure has reached.
     */
    private function stage(Procedure $procedure): string
    {
        return match (true) {
            $procedure->discharged_at !== null => 'discharged',
            $procedure->post_op_completed_at !== null => 'post-op done',
            $procedure->operation_completed_at !== null => 'operation done',
            $procedure->operation_started_at !== null => 'in operation',
            $procedure->pre_op_completed_at !== null => 'pre-op done',
            $procedure->admitted_at !== null => 'admitted',
            default => 'booked',
        };
    }
}
