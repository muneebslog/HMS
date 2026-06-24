<?php

use App\Http\Controllers\Api\PrintJobController;
use Illuminate\Support\Facades\Route;

Route::middleware('print.agent')->group(function () {
    Route::get('/print-jobs/pending', [PrintJobController::class, 'pending'])->name('api.print-jobs.pending');
    Route::post('/print-jobs/{job}/printed', [PrintJobController::class, 'printed'])->name('api.print-jobs.printed');
    Route::post('/print-jobs/{job}/failed', [PrintJobController::class, 'failed'])->name('api.print-jobs.failed');
});
