<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\LabInvoice;
use App\Services\LabReportBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LabCaseReportController extends Controller
{
    /**
     * Show the printable report for a case's completed in-house tests, or for
     * a single test when `?item=` is given. Each test prints on its own A4 page.
     */
    public function __invoke(Request $request, LabInvoice $labInvoice, LabReportBuilder $builder): View
    {
        $report = $builder->caseReport($labInvoice, $request->filled('item') ? (int) $request->query('item') : null);

        abort_if($report === null, 404, __('No completed results to show for this case.'));

        return view('lab.reports.report', $report);
    }
}
