<?php

namespace App\Http\Controllers\Lab;

use App\Enums\OutgoingSampleStatus;
use App\Http\Controllers\Controller;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Services\LabReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public (no login) results page the patient reaches by scanning the QR on their lab slip.
 * Found only by the invoice's random public code, never by the sequential receipt number.
 */
class PublicLabResultsController extends Controller
{
    /**
     * Show the patient's tests and which results are ready.
     */
    public function show(string $token): Response
    {
        $labInvoice = $this->findInvoice($token);
        $labInvoice->load(['patient', 'items.labTest']);

        $items = $labInvoice->items
            ->sortBy([['is_in_house', 'desc'], ['id', 'asc']])
            ->values()
            ->map(fn (LabInvoiceItem $item) => [
                'id' => $item->id,
                'name' => $item->labTest?->reportTitle() ?? trim((string) $item->test_name),
                'status' => $this->status($item),
                'has_report' => $item->results_completed_at !== null,
                'has_file' => ! $item->is_in_house && $item->hasReport(),
            ]);

        return $this->noIndex(response()->view('lab.public.results', [
            'labInvoice' => $labInvoice,
            'items' => $items,
            'readyCount' => $items->filter(fn (array $item) => $item['has_report'] || $item['has_file'])->count(),
            'printableCount' => $items->where('has_report', true)->count(),
        ]));
    }

    /**
     * Stream the partner lab's uploaded report for one of the patient's outsourced tests.
     */
    public function file(string $token, int $item): StreamedResponse
    {
        $labInvoice = $this->findInvoice($token);
        $labInvoiceItem = $labInvoice->items()->where('is_in_house', false)->findOrFail($item);

        abort_unless($labInvoiceItem->hasReport(), 404);

        $response = Storage::disk('local')->response($labInvoiceItem->report_path, $labInvoiceItem->report_original_name);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Show the printable report for the patient's completed tests (or one test with `?item=`).
     */
    public function report(Request $request, string $token, LabReportBuilder $builder): Response
    {
        $labInvoice = $this->findInvoice($token);
        $report = $builder->caseReport($labInvoice, $request->filled('item') ? (int) $request->query('item') : null);

        abort_if($report === null, 404);

        return $this->noIndex(response()->view('lab.reports.report', $report));
    }

    /**
     * Find a (not returned) lab invoice by its public code, or 404.
     */
    private function findInvoice(string $token): LabInvoice
    {
        abort_unless(strlen($token) >= 16, 404);

        return LabInvoice::query()
            ->where('public_token', $token)
            ->where('status', '!=', 'returned')
            ->firstOrFail();
    }

    /**
     * Describe a test's progress for the patient.
     *
     * @return array{label: string, tone: string}
     */
    private function status(LabInvoiceItem $item): array
    {
        if ($item->isDone()) {
            return $item->results_completed_at !== null || $item->hasReport()
                ? ['label' => __('Ready'), 'tone' => 'ready']
                : ['label' => __('Ready — collect from reception'), 'tone' => 'ready'];
        }

        if (! $item->is_in_house) {
            return $item->outgoing_status === OutgoingSampleStatus::Received
                ? ['label' => __('Ready — collect from reception'), 'tone' => 'ready']
                : ['label' => __('Sent to partner lab'), 'tone' => 'waiting'];
        }

        return ['label' => __('In process'), 'tone' => 'waiting'];
    }

    /**
     * Keep patient pages out of search engines and caches.
     */
    private function noIndex(Response $response): Response
    {
        return $response
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }
}
