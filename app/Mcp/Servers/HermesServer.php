<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\DailyDigestTool;
use App\Mcp\Tools\LabStatusTool;
use App\Mcp\Tools\PrescriptionsTool;
use App\Mcp\Tools\ProceduresTool;
use App\Mcp\Tools\ShiftSummaryTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Mohsin Medical Complex HMS')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Read-only reports from the live hospital management system of Mohsin Medical Complex (Lahore). Times are Asia/Karachi.
A business day starts at 18:00 the evening before with the night shift, followed by the morning and evening shifts; "today" means the current business day.
Start with daily_digest for an overview, then use lab_status, general_checkup_prescriptions, procedures or shift_summary for details.
Patients are identified by MR number only; names, phone numbers and CNIC are never provided. Money is in PKR.
TEXT)]
class HermesServer extends Server
{
    protected array $tools = [
        DailyDigestTool::class,
        LabStatusTool::class,
        PrescriptionsTool::class,
        ProceduresTool::class,
        ShiftSummaryTool::class,
    ];
}
