<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabInvoice;
use App\Models\PrintJob;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrintJobController extends Controller
{
    /**
     * Get pending print jobs for the reception agent.
     */
    public function pending(): JsonResponse
    {
        $jobs = PrintJob::pending()
            ->with([
                'invoice.items.queueToken',
                'invoice.patient',
                'labInvoice.items',
                'labInvoice.patient',
                'shift.user',
                'shift.expenses',
            ])
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $jobs->map(fn (PrintJob $job) => $this->formatJob($job)),
        ]);
    }

    /**
     * Mark a print job as printed.
     */
    public function printed(PrintJob $job): JsonResponse
    {
        $job->markAsPrinted();

        return response()->json([
            'message' => 'Print job marked as printed.',
            'data' => $this->formatJob($job),
        ]);
    }

    /**
     * Mark a print job as failed.
     */
    public function failed(Request $request, PrintJob $job): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'error_message' => ['required', 'string', 'max:1000'],
        ])->validate();

        $job->markAsFailed($validated['error_message']);

        return response()->json([
            'message' => 'Print job marked as failed.',
            'data' => $this->formatJob($job),
        ]);
    }

    /**
     * Format a print job for the agent response.
     *
     * @return array<string, mixed>
     */
    protected function formatJob(PrintJob $job): array
    {
        $invoice = $job->invoice;
        $labInvoice = $job->labInvoice;
        $shift = $job->shift;

        if ($shift instanceof Shift) {
            return [
                'id' => $job->id,
                'status' => $job->status->value,
                'payload' => $job->payload,
                'attempts' => $job->attempts,
                'shift' => [
                    'id' => $shift->id,
                    'user' => [
                        'name' => $shift->user->name,
                    ],
                    'opened_at' => $shift->opened_at->format('Y-m-d H:i'),
                    'closed_at' => $shift->closed_at?->format('Y-m-d H:i'),
                    'opening_balance' => $shift->opening_balance,
                    'closing_balance' => $shift->closing_balance,
                    'total_walk_in_sales' => $shift->totalWalkInSales(),
                    'total_lab_sales' => $shift->totalLabSales(),
                    'total_procedure_sales' => $shift->totalProcedureSales(),
                    'total_sales' => $shift->totalSales(),
                    'total_expenses' => $shift->totalExpenses(),
                    'total_daily_payouts' => $shift->totalDailyPayouts(),
                    'expected_cash' => $shift->expectedCash(),
                    'expenses' => $shift->expenses->map(fn ($expense) => [
                        'name' => $expense->name,
                        'amount' => $expense->amount,
                    ]),
                ],
            ];
        }

        if ($labInvoice instanceof LabInvoice) {
            return [
                'id' => $job->id,
                'status' => $job->status->value,
                'payload' => $job->payload,
                'attempts' => $job->attempts,
                'invoice' => [
                    'id' => $labInvoice->id,
                    'invoice_number' => $labInvoice->invoice_number,
                    'qr_url' => $job->payload['qr_url'] ?? null,
                    'copy_for' => $job->payload['copy_for'] ?? null,
                    'total' => $labInvoice->total,
                    'created_at' => $labInvoice->created_at->format('Y-m-d H:i'),
                    'patient' => [
                        'name' => $labInvoice->patient->name,
                        'mrn' => $labInvoice->patient->mrn,
                        'age' => $labInvoice->patient->age,
                        'gender' => $labInvoice->patient->gender,
                    ],
                    'items' => $labInvoice->items->map(fn ($item) => [
                        'service_name' => $item->test_name,
                        'test_code' => $item->test_code,
                        'time_required' => $item->time_required,
                        'price' => $item->price,
                        'doctor_name' => null,
                        'token_number' => null,
                    ]),
                ],
            ];
        }

        return [
            'id' => $job->id,
            'status' => $job->status->value,
            'payload' => $job->payload,
            'attempts' => $job->attempts,
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total' => $invoice->total,
                'created_at' => $invoice->created_at->format('Y-m-d H:i'),
                'patient' => [
                    'name' => $invoice->patient->name,
                ],
                'items' => $invoice->items->map(fn ($item) => [
                    'service_name' => $item->service_name,
                    'price' => $item->price,
                    'doctor_name' => $item->doctor_name,
                    'token_number' => $item->queueToken?->token_number,
                ]),
            ],
        ];
    }
}
