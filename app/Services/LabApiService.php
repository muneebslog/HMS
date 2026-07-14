<?php

namespace App\Services;

use App\Models\AdminNotification;
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
            return true;
        }

        $sendableItems = $invoice->items->filter(fn ($item) => $item->is_in_house && ctype_digit((string) $item->test_code));
        $skippedItems = $invoice->items->filter(fn ($item) => $item->is_in_house && ! ctype_digit((string) $item->test_code));

        if ($skippedItems->isNotEmpty()) {
            $this->notifySkippedItems($invoice, $skippedItems);
        }

        if ($sendableItems->isEmpty()) {
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

        try {
            $response = Http::timeout(15)
                ->withToken(config('services.lab.token'))
                ->post(rtrim(config('services.lab.url'), '/').'/api/hms/lab-cases', $payload);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Lab API returned non-successful response.', [
                'invoice_number' => $invoice->invoice_number,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Failed to send lab case to lab API.', [
                'invoice_number' => $invoice->invoice_number,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
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
