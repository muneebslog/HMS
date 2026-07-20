<?php

namespace App\Services;

use App\Enums\LabApiStatus;
use App\Models\AdminNotification;
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
            'phone' => $invoice->patient->phone,
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
     * Build the external lab case URL for the given invoice.
     */
    private function labCaseUrl(LabInvoice $invoice): string
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
        $testNames = $items->map(fn ($item) => $item->test_name)->implode(', ');

        AdminNotification::create([
            'user_id' => $invoice->created_by,
            'type' => 'lab_test_missing_code',
            'title' => __('Lab test missing code'),
            'message' => __('Invoice :invoice has in-house tests without numeric codes and were not sent to the lab: :tests.', [
                'invoice' => $invoice->invoice_number,
                'tests' => $testNames,
            ]),
            'actionable_url' => route('reception.invoices'),
            'metadata' => [
                'lab_invoice_id' => $invoice->id,
                'test_names' => $items->map(fn ($item) => $item->test_name)->all(),
            ],
        ]);
    }
}
