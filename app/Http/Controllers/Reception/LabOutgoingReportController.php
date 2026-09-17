<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Models\LabInvoiceItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LabOutgoingReportController extends Controller
{
    /**
     * Stream an uploaded outgoing lab report PDF.
     */
    public function __invoke(LabInvoiceItem $item): StreamedResponse
    {
        if ($item->is_in_house || ! $item->hasReport()) {
            abort(404);
        }

        $filename = $item->report_original_name ?: ('lab-report-'.$item->id.'.pdf');

        return Storage::disk('local')->response(
            $item->report_path,
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
