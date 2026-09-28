<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LabCaseReportFileController extends Controller
{
    /**
     * Stream the partner lab's uploaded report for one of a case's outsourced tests, inline for viewing.
     */
    public function __invoke(LabInvoice $labInvoice, LabInvoiceItem $item): StreamedResponse
    {
        abort_unless($item->hasReport(), 404);

        return Storage::disk('local')->response($item->report_path, $item->report_original_name);
    }
}
