<?php

use App\Http\Controllers\Api\PdfPrintJobController;
use App\Http\Controllers\Api\PrintJobController;
use Illuminate\Support\Facades\Route;

Route::middleware('print.agent')->group(function () {
    Route::get('/print-jobs/pending', [PrintJobController::class, 'pending'])->name('api.print-jobs.pending');
    Route::post('/print-jobs/{job}/printed', [PrintJobController::class, 'printed'])->name('api.print-jobs.printed');
    Route::post('/print-jobs/{job}/failed', [PrintJobController::class, 'failed'])->name('api.print-jobs.failed');
});

Route::middleware('pdf.print.agent')->group(function () {
    Route::get('/pdf-print-jobs/pending', [PdfPrintJobController::class, 'pending'])->name('api.pdf-print-jobs.pending');
    Route::get('/pdf-print-jobs/{job}/file', [PdfPrintJobController::class, 'file'])->name('api.pdf-print-jobs.file');
    Route::post('/pdf-print-jobs/{job}/printed', [PdfPrintJobController::class, 'printed'])->name('api.pdf-print-jobs.printed');
    Route::post('/pdf-print-jobs/{job}/failed', [PdfPrintJobController::class, 'failed'])->name('api.pdf-print-jobs.failed');
});
