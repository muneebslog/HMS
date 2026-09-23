<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
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
        $labInvoice->load(['patient.family', 'referredByDoctor']);

        $items = $labInvoice->items()
            ->with(['labTest.fields.ranges', 'results'])
            ->where('is_in_house', true)
            ->whereNotNull('results_completed_at')
            ->when($request->filled('item'), fn ($query) => $query->whereKey((int) $request->query('item')))
            ->orderBy('id')
            ->get();

        abort_if($items->isEmpty(), 404, __('No completed results to show for this case.'));

        $patient = $labInvoice->patient;

        $sections = $items
            ->filter(fn (LabInvoiceItem $item) => $item->labTest !== null)
            ->map(fn (LabInvoiceItem $item) => $builder->buildSection(
                $item->labTest,
                $item->results->pluck('value', 'lab_field_id')->all(),
                $patient?->gender,
                $patient?->age,
                $item->result_comment,
            ))
            ->filter()
            ->values()
            ->all();

        $ageSex = collect([
            $patient?->age !== null ? __(':age Years', ['age' => $patient->age]) : null,
            $patient?->gender ? ucfirst($patient->gender) : null,
        ])->filter()->implode(' / ');

        return view('lab.reports.report', [
            'header' => [
                'title' => __('Laboratory Report'),
                'number' => $labInvoice->invoice_number,
                'mrn' => $patient?->mrn,
                'patient_name' => $patient?->name ?? __('Unknown patient'),
                'age_sex' => $ageSex !== '' ? $ageSex : null,
                'phone' => $patient?->contactPhone(),
                'referred_by' => $labInvoice->referredByDoctor?->name,
                'sample_date' => $labInvoice->created_at->format('d M Y, g:i A'),
                'report_date' => $items->max('results_completed_at')?->format('d M Y, g:i A'),
            ],
            'sections' => $sections,
            'remarks' => null,
        ]);
    }
}
