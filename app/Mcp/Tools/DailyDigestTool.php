<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesReportPeriod;
use App\Services\Hermes\LabStatusReport;
use App\Services\Hermes\PrescriptionsReport;
use App\Services\Hermes\ProceduresReport;
use App\Services\Hermes\ShiftSummaryReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('daily_digest')]
#[IsReadOnly]
#[Description('One compact overview of a business day or shift: headline numbers from the lab, general checkup prescriptions, procedures and every shift. Use this for a daily summary, then the other tools for details.')]
class DailyDigestTool extends Tool
{
    use ResolvesReportPeriod;

    public function handle(
        Request $request,
        LabStatusReport $lab,
        PrescriptionsReport $prescriptions,
        ProceduresReport $procedures,
        ShiftSummaryReport $shifts,
    ): Response|ResponseFactory {
        try {
            [$period] = $this->resolvePeriod($request);
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        $labReport = $lab->build(5);
        $prescriptionReport = $prescriptions->build($period, 0);
        $shiftReport = $shifts->build($period);

        return Response::structured([
            'period' => $period->toArray(),
            'lab_now' => [
                ...$labReport['today'],
                'open_by_stage' => collect($labReport['stages'])->mapWithKeys(fn (array $stage) => [$stage['key'] => $stage['count']])->all(),
            ],
            'general_checkup' => [
                ...$prescriptionReport['totals'],
                'entered_after_shift_closed' => $prescriptionReport['entered_after_shift_closed']['count'],
                'entered_this_period_for_earlier_patients' => $prescriptionReport['entered_this_period_for_earlier_patients']['count'],
                'given_from_earlier_prescriptions' => $prescriptionReport['given_this_period_from_earlier_prescriptions'],
            ],
            'procedures' => $procedures->build($period, 0)['counts'],
            'money' => $shiftReport['totals']['money'],
            'shifts' => collect($shiftReport['shifts'])->map(fn (array $shift) => [
                'id' => $shift['id'],
                'period' => $shift['period'],
                'opened_by' => $shift['opened_by'],
                'status' => $shift['status'],
                'total_sales' => $shift['money']['total_sales'],
                'expected_cash' => $shift['money']['expected_cash'],
                'settlement_difference' => $shift['settlement']['difference'] ?? null,
                'pending_approvals' => $shift['counts']['pending_approvals'],
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return collect($this->periodSchema($schema))->except('detail_limit')->all();
    }
}
