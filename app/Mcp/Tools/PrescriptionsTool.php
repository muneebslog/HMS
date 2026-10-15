<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesReportPeriod;
use App\Services\Hermes\PrescriptionsReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('general_checkup_prescriptions')]
#[IsReadOnly]
#[Description('General checkup prescriptions for a business day or shift: how many patients came to prescription services (General Checkup), how many got a prescription entered in the HMS and how many did not, entry delay, prescriptions entered after the shift closed, prescriptions entered in this period for patients from earlier shifts, medicines and injections given in this period from earlier prescriptions, and medicine, injection and drip delivery (ordered vs given).')]
class PrescriptionsTool extends Tool
{
    use ResolvesReportPeriod;

    public function handle(Request $request, PrescriptionsReport $report): Response|ResponseFactory
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
