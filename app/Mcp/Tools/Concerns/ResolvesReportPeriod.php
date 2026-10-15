<?php

namespace App\Mcp\Tools\Concerns;

use App\Services\Hermes\ReportPeriod;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

/**
 * Shared "which day or shift" arguments for Hermes report tools.
 */
trait ResolvesReportPeriod
{
    /**
     * @return array<string, mixed>
     */
    protected function periodSchema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description("Business day as YYYY-MM-DD, 'today' or 'yesterday'. A business day starts at 18:00 the evening before (night shift), then morning and evening. Defaults to today. Ignored when shift is given."),
            'shift' => $schema->string()->description("A shift id (e.g. '412') or 'current' for the shift open right now. Use shift_summary to find shift ids."),
            'detail_limit' => $schema->integer()->min(1)->max(50)->description('Maximum rows in each detail list. Defaults to 15.'),
        ];
    }

    /**
     * @return array{0: ReportPeriod, 1: int}
     */
    protected function resolvePeriod(Request $request): array
    {
        $validated = $request->validate([
            'date' => ['nullable', 'string', 'max:20'],
            'shift' => ['nullable', 'regex:/^(current|\d+)$/'],
            'detail_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ], [
            'shift.regex' => "Shift must be a shift id number or 'current'.",
        ]);

        return [
            ReportPeriod::resolve($validated['date'] ?? null, $validated['shift'] ?? null),
            (int) ($validated['detail_limit'] ?? 15),
        ];
    }
}
