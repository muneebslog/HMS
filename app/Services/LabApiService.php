<?php

namespace App\Services;

use App\Enums\LabApiStatus;
use App\Enums\LabResultsStatus;
use App\Models\LabApiLog;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LabApiService
{
    /**
     * Check whether the lab API integration is enabled.
     */
    public function enabled(): bool
    {
        return config('services.lab.enabled', true)
            && filled(config('services.lab.url'))
            && filled(config('services.lab.token'));
    }

    /**
     * Send the in-house tests of a lab invoice to the lab application.
     */
    public function sendLabCase(LabInvoice $invoice): bool
    {
        if (! $this->enabled()) {
            $this->recordLog($invoice, LabApiStatus::Skipped, null, null, null, __('Lab API integration is disabled.'));

            return true;
        }

        $sendableItems = $invoice->items->filter(fn ($item) => $item->is_in_house && ctype_digit((string) $item->test_code));
        $skippedItems = $invoice->items->filter(fn ($item) => $item->is_in_house && ! ctype_digit((string) $item->test_code));

        if ($skippedItems->isNotEmpty()) {
            $this->notifySkippedItems($invoice, $skippedItems);
        }

        if ($sendableItems->isEmpty()) {
            $this->recordLog($invoice, LabApiStatus::Skipped, null, null, null, __('No in-house tests with numeric codes to send.'));

            return true;
        }

        $payload = [
            'name' => $invoice->patient->name,
            'phone' => $invoice->patient->contactPhone(),
            'invoice_number' => $invoice->invoice_number,
            'receipt_no' => null,
            'age' => $invoice->patient->age,
            'age_unit' => 'Year',
            'gender' => $invoice->patient->gender,
            'test_codes' => $sendableItems->map(fn ($item) => $item->test_code)->values()->all(),
        ];

        $labCaseUrl = $this->labCaseUrl($invoice);

        $this->recordLog($invoice, LabApiStatus::Pending, $payload, null, null, null, $labCaseUrl);

        try {
            $response = Http::timeout(15)
                ->withToken(config('services.lab.token'))
                ->post(rtrim(config('services.lab.url'), '/').'/api/hms/lab-cases', $payload);

            if ($response->successful()) {
                $this->recordLog($invoice, LabApiStatus::Sent, $payload, $response->body(), $response->status(), null, $labCaseUrl);

                return true;
            }

            $this->recordLog($invoice, LabApiStatus::Failed, $payload, $response->body(), $response->status(), null, $labCaseUrl);

            Log::warning('Lab API returned non-successful response.', [
                'invoice_number' => $invoice->invoice_number,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            $this->recordLog($invoice, LabApiStatus::Failed, $payload, null, null, $e->getMessage(), $labCaseUrl);

            Log::error('Failed to send lab case to lab API.', [
                'invoice_number' => $invoice->invoice_number,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Fetch machine-readable status for a lab case from the lab application.
     *
     * @return array<string, mixed>|null
     */
    public function fetchLabCaseStatus(string $invoiceNumber): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withToken(config('services.lab.token'))
                ->get(rtrim(config('services.lab.url'), '/').'/api/hms/lab-cases/'.$invoiceNumber);

            if ($response->status() === 404) {
                return null;
            }

            if (! $response->successful()) {
                Log::warning('Lab status API returned non-successful response.', [
                    'invoice_number' => $invoiceNumber,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->json();

            return $payload;
        } catch (\Throwable $e) {
            Log::error('Failed to fetch lab case status.', [
                'invoice_number' => $invoiceNumber,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Sync in-house result readiness for a single lab invoice from the lab API.
     */
    public function syncInvoiceStatuses(LabInvoice $invoice): bool
    {
        $invoice->loadMissing(['items', 'labApiLog']);

        $inHouseItems = $invoice->items->filter(fn (LabInvoiceItem $item) => $item->is_in_house);

        if ($inHouseItems->isEmpty()) {
            $invoice->update([
                'lab_results_status' => LabResultsStatus::Unknown,
                'lab_results_synced_at' => now(),
            ]);

            return true;
        }

        $payload = $this->fetchLabCaseStatus($invoice->invoice_number);

        if ($payload === null) {
            return false;
        }

        /** @var Collection<string, bool> $readyByCode */
        $readyByCode = collect($payload['tests'] ?? [])
            ->filter(fn ($test) => filled($test['test_code'] ?? null))
            ->mapWithKeys(fn ($test) => [
                (string) $test['test_code'] => (bool) ($test['is_result_added'] ?? false),
            ]);

        foreach ($inHouseItems as $item) {
            $code = (string) $item->test_code;
            $ready = $readyByCode->has($code) ? $readyByCode->get($code) : null;

            $item->update([
                'lab_result_ready' => $ready,
            ]);
        }

        $invoice->refresh()->load('items');

        $inHouseItems = $invoice->items->filter(fn (LabInvoiceItem $item) => $item->is_in_house);
        $known = $inHouseItems->filter(fn (LabInvoiceItem $item) => $item->lab_result_ready !== null);
        $readyCount = $known->where('lab_result_ready', true)->count();
        $knownCount = $known->count();

        $status = match (true) {
            $knownCount === 0 => LabResultsStatus::Unknown,
            $readyCount === 0 => LabResultsStatus::Pending,
            $readyCount < $inHouseItems->count() => LabResultsStatus::Partial,
            default => LabResultsStatus::Ready,
        };

        $invoiceUrl = $payload['invoice_url'] ?? $this->labCaseUrl($invoice);

        $invoice->update([
            'lab_results_status' => $status,
            'lab_results_synced_at' => now(),
        ]);

        if ($invoice->labApiLog !== null && filled($invoiceUrl)) {
            $invoice->labApiLog->update([
                'lab_case_url' => $invoiceUrl,
            ]);
        }

        return true;
    }

    /**
     * Sync statuses for invoices that still need in-house result updates.
     */
    public function syncPendingInvoices(int $limit = 50): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $invoices = LabInvoice::query()
            ->with(['items', 'labApiLog'])
            ->whereHas('items', fn ($query) => $query->where('is_in_house', true))
            ->whereHas('labApiLog', fn ($query) => $query->where('status', LabApiStatus::Sent))
            ->where(function ($query) {
                $query->whereNull('lab_results_status')
                    ->orWhere('lab_results_status', '!=', LabResultsStatus::Ready->value);
            })
            ->latest('id')
            ->limit($limit)
            ->get();

        $synced = 0;

        foreach ($invoices as $invoice) {
            if ($this->syncInvoiceStatuses($invoice)) {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Build the external lab case URL for the given invoice.
     */
    public function labCaseUrl(LabInvoice $invoice): string
    {
        return rtrim((string) config('services.lab.url'), '/').'/my-visit/'.$invoice->invoice_number;
    }

    /**
     * Upsert the API log for the invoice.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function recordLog(
        LabInvoice $invoice,
        LabApiStatus $status,
        ?array $payload,
        ?string $responseBody,
        ?int $httpStatus,
        ?string $errorMessage,
        ?string $labCaseUrl = null,
    ): void {
        LabApiLog::updateOrCreate(
            ['lab_invoice_id' => $invoice->id],
            [
                'status' => $status,
                'request_payload' => $payload,
                'response_body' => $responseBody,
                'http_status' => $httpStatus,
                'error_message' => $errorMessage,
                'sent_at' => $status === LabApiStatus::Sent ? now() : null,
                'lab_case_url' => $labCaseUrl ?? $this->labCaseUrl($invoice),
            ]
        );
    }

    /**
     * Notify admins about in-house tests that could not be sent due to missing codes.
     *
     * @param  Collection<int, LabInvoiceItem>  $items
     */
    private function notifySkippedItems(LabInvoice $invoice, $items): void
    {
        app(NotificationService::class)->notifyLabTestMissingCode($invoice, $items);
    }
}
