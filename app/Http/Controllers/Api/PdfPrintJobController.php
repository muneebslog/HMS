<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PdfPrintJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PdfPrintJobController extends Controller
{
    /**
     * Get pending PDF print jobs for the agent.
     */
    public function pending(): JsonResponse
    {
        $jobs = PdfPrintJob::pending()
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $jobs->map(fn (PdfPrintJob $job) => $this->formatJob($job)),
        ]);
    }

    /**
     * Stream the stored PDF file for a print job.
     */
    public function file(PdfPrintJob $job): StreamedResponse|JsonResponse
    {
        if (! Storage::disk('local')->exists($job->disk_path)) {
            return response()->json(['message' => 'PDF file not found.'], 404);
        }

        return Storage::disk('local')->response(
            $job->disk_path,
            $job->original_filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Mark a PDF print job as printed.
     */
    public function printed(PdfPrintJob $job): JsonResponse
    {
        $job->markAsPrinted();

        return response()->json([
            'message' => 'PDF print job marked as printed.',
            'data' => $this->formatJob($job),
        ]);
    }

    /**
     * Mark a PDF print job as failed.
     */
    public function failed(Request $request, PdfPrintJob $job): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'error_message' => ['required', 'string', 'max:1000'],
        ])->validate();

        $job->markAsFailed($validated['error_message']);

        return response()->json([
            'message' => 'PDF print job marked as failed.',
            'data' => $this->formatJob($job),
        ]);
    }

    /**
     * Format a PDF print job for the agent response.
     *
     * @return array<string, mixed>
     */
    protected function formatJob(PdfPrintJob $job): array
    {
        return [
            'id' => $job->id,
            'status' => $job->status->value,
            'original_filename' => $job->original_filename,
            'attempts' => $job->attempts,
            'created_at' => $job->created_at?->format('Y-m-d H:i:s'),
            'download_url' => '/api/pdf-print-jobs/'.$job->id.'/file',
        ];
    }
}
