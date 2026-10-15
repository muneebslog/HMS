<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesReportPeriod;
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

#[Name('shift_summary')]
#[IsReadOnly]
#[Description('Shift summary for a business day or one shift: per shift the receptionist, open and close time, OPD, lab and procedure sales, cash vs online, expenses, doctor payouts, expected cash, settlement and shortages, slip and return counts, pending approvals, and OPD slips per service. Also lists the shift ids of the day.')]
class ShiftSummaryTool extends Tool
{
    use ResolvesReportPeriod;

    public function handle(Request $request, ShiftSummaryReport $report): Response|ResponseFactory
    {
        try {
            [$period] = $this->resolvePeriod($request);
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured($report->build($period));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return collect($this->periodSchema($schema))->except('detail_limit')->all();
    }
}
