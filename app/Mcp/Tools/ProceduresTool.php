<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesReportPeriod;
use App\Services\Hermes\ProceduresReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('procedures')]
#[IsReadOnly]
#[Description('Procedures (admissions, operations, deliveries) for a business day or shift: how many were added, admitted, operated and discharged, counts by procedure type, the list added in the period with stage and payment balance, and patients still on the ward.')]
class ProceduresTool extends Tool
{
    use ResolvesReportPeriod;

    public function handle(Request $request, ProceduresReport $report): Response|ResponseFactory
    {
        try {
            [$period, $detailLimit] = $this->resolvePeriod($request);
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured($report->build($period, $detailLimit));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->periodSchema($schema);
    }
}
