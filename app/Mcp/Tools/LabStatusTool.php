<?php

namespace App\Mcp\Tools;

use App\Services\Hermes\LabStatusReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('lab_status')]
#[IsReadOnly]
#[Description('Live lab status right now: tests billed and done today, open tests per stage (sample not in lab, results pending, rider not called, waiting for rider, with partner lab, retake), samples still to send out to the partner lab, retakes waiting for the patient, and late tests.')]
class LabStatusTool extends Tool
{
    public function handle(Request $request, LabStatusReport $report): ResponseFactory
    {
        $validated = $request->validate(['detail_limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return Response::structured($report->build((int) ($validated['detail_limit'] ?? 15)));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'detail_limit' => $schema->integer()->min(1)->max(50)->description('Maximum rows in each detail list. Defaults to 15.'),
        ];
    }
}
