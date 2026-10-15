<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesReportPeriod;
use App\Services\Hermes\NeedsAttentionReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('needs_attention')]
#[IsReadOnly]
#[Description('What the owner should follow up on, each with who to ask: late lab results, samples billed but not taken, outsourced samples not sent, retakes not contacted, general checkup patients without a prescription (per shift, with who entered them), medicines not marked given, drips without an order, patients left on the ward, files not printed, discharged patients with dues or no birth certificate, cash shortages, unsettled shifts and pending approvals. The "message" field is a ready-to-send WhatsApp summary.')]
class NeedsAttentionTool extends Tool
{
    use ResolvesReportPeriod;

    public function handle(Request $request, NeedsAttentionReport $report): Response|ResponseFactory
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
