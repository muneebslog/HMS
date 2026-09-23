<?php

use App\Enums\UserRole;
use App\Http\Controllers\Display\TokenDisplayController;
use App\Http\Controllers\DriveFileController;
use App\Http\Controllers\EmployeeDocumentController;
use App\Http\Controllers\EmployeePhotoController;
use App\Http\Controllers\EmployeeQualificationDownloadController;
use App\Http\Controllers\Indoor\ProcedureAttachmentController;
use App\Http\Controllers\Indoor\ProcedureBirthCertificateController;
use App\Http\Controllers\Indoor\ProcedureDischargeCertificateController;
use App\Http\Controllers\Management\ProcedureTypeDocumentPreviewController;
use App\Http\Controllers\PolicyJournalController;
use App\Http\Controllers\Reception\ProcedureApparentInvoicePrintController;
use App\Http\Controllers\Reception\ProcedureFileController;
use App\Http\Controllers\Reception\ProcedurePrintController;
use App\Http\Controllers\Reception\QueueTvController;
use App\Http\Middleware\RedirectLegacyDisplayDevices;
use App\Models\Invoice;
use App\Models\Shift;
use App\Services\ShiftOrdersExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('display/tokens', 'pages::display.token-display')
    ->middleware(RedirectLegacyDisplayDevices::class)
    ->name('display.tokens');

Route::get('display/tokens/tv', [TokenDisplayController::class, 'tv'])->name('display.tokens.tv');

Route::post('display/tokens/tv/select', [TokenDisplayController::class, 'selectQueue'])->name('display.tokens.tv.select');
Route::post('display/tokens/tv/next', [TokenDisplayController::class, 'callNext'])->name('display.tokens.tv.next');
Route::post('display/tokens/tv/back', [TokenDisplayController::class, 'callPrevious'])->name('display.tokens.tv.back');
Route::post('display/tokens/tv/start-serving', [TokenDisplayController::class, 'startServing'])->name('display.tokens.tv.start-serving');
Route::post('display/tokens/tv/mark-served', [TokenDisplayController::class, 'markServed'])->name('display.tokens.tv.mark-served');

Route::livewire('display/tokens/control', 'pages::display.token-control')
    ->middleware(['auth'])
    ->name('display.tokens.control');

Route::livewire('display/er', 'pages::display.medication-delivery')
    ->name('display.er');

Route::livewire('display/medication', 'pages::display.medication-delivery')
    ->name('display.medication');

Route::livewire('display/drips', 'pages::display.drip-delivery')
    ->name('display.drips');

Route::view('display/er-drips', 'pages.display.er-drips')
    ->name('display.er_drips');

Route::livewire('display/shift-orders', 'pages::display.shift-orders')
    ->name('display.shift_orders');

Route::get('display/shift-orders/export', function (Request $request, ShiftOrdersExportService $export) {
    $validated = $request->validate([
        'shiftId' => ['required', 'integer', 'exists:shifts,id'],
        'type' => ['required', 'string', 'in:'.implode(',', ShiftOrdersExportService::types())],
    ]);

    $shift = Shift::query()->findOrFail($validated['shiftId']);
    $type = $validated['type'];

    return view('display.shift-orders-export', [
        'shift' => $shift,
        'type' => $type,
        'typeLabel' => $export->typeLabel($type),
        'rows' => $export->rowsForShift($shift, $type),
    ]);
})->name('display.shift_orders.export');

Route::middleware(['auth', 'verified', 'role.assigned'])->group(function () {
    Route::middleware('role:'.UserRole::Admin->value)->group(function () {
        Route::livewire('admin/page-access', 'pages::admin.page-access')->name('admin.page-access');
        Route::livewire('admin/act-as-role', 'pages::admin.act-as-role')->name('admin.act-as-role');
    });

    Route::middleware('page.access')->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

        Route::livewire('pending-role', 'pages::pending-role')->name('pending-role');

        Route::livewire('extras', 'pages::extras')->name('extras');
        Route::livewire('extras/admin-side', 'pages::extras.admin-side')->name('extras.admin-side');
        Route::livewire('extras/dev-side', 'pages::extras.dev-side')->name('extras.dev-side');
        Route::livewire('extras/stats', 'pages::extras.stats')->name('extras.stats');

        Route::livewire('management/crud', 'pages::management.crud')->name('management.crud');
        Route::get('management/procedure-type-documents/{document}/preview', ProcedureTypeDocumentPreviewController::class)
            ->name('management.procedure-type-documents.preview');
        Route::livewire('admin/users', 'pages::admin.users')->name('admin.users');
        Route::livewire('admin/sms-logs', 'pages::admin.sms-logs')->name('admin.sms-logs');
        Route::livewire('admin/merge-duplicates', 'pages::admin.merge-duplicates')->name('admin.merge-duplicates');
        Route::livewire('admin/sql-runner', 'pages::admin.sql-runner')->name('admin.sql-runner');
        Route::livewire('admin/kanban', 'pages::admin.kanban')->name('admin.kanban');
        Route::livewire('admin/employees', 'pages::admin.employees')->name('admin.employees');
        Route::livewire('admin/employees/{employee}/profile', 'pages::admin.employee-profile')->name('admin.employees.profile');
        Route::livewire('admin/health-aides', 'pages::admin.health-aides')->name('admin.health-aides');
        Route::livewire('admin/policy-journal', 'pages::admin.policy-journal')->name('admin.policy-journal');
        Route::get('admin/policy-journals/{policyJournal}/attachments/{index}/download', [PolicyJournalController::class, 'download'])
            ->name('admin.policy-journals.download');
        Route::livewire('admin/reports', 'pages::admin.reports')->name('admin.reports');
        Route::livewire('admin/monthly-report', 'pages::admin.monthly-report')->name('admin.monthly-report');
        Route::livewire('admin/finance', 'pages::admin.finance')->name('admin.finance');
        Route::livewire('admin/procedure-finances', 'pages::admin.procedure-finances')->name('admin.procedure-finances');
        Route::livewire('admin/service-stats', 'pages::admin.service-stats')->name('admin.service-stats');
        Route::livewire('admin/medication-deliveries', 'pages::admin.medication-deliveries')->name('admin.medication-deliveries');

        Route::get('employee-documents/{document}/download', [EmployeeDocumentController::class, 'download'])->name('employee-documents.download');
        Route::get('employee-photos/{employee}', EmployeePhotoController::class)->name('employee-photos.show');
        Route::get('employee-qualifications/{qualification}/download', EmployeeQualificationDownloadController::class)->name('employee-qualifications.download');

        Route::livewire('doctor/portal', 'pages::doctor.portal')->name('doctor.portal');
        Route::livewire('doctor/medication', 'pages::doctor.medication')->name('doctor.medication');
        Route::livewire('doctor/procedures', 'pages::doctor.procedures')->name('doctor.procedures');

        Route::get('indoor/attachments/{attachment}', ProcedureAttachmentController::class)->name('indoor.attachments.show');
        Route::get('indoor/procedures/{procedure}/discharge-certificate', ProcedureDischargeCertificateController::class)->name('indoor.procedures.discharge-certificate');
        Route::get('indoor/procedures/{procedure}/birth-certificate', ProcedureBirthCertificateController::class)->name('indoor.procedures.birth-certificate');
        Route::get('indoor/procedures/{procedure}/bill', ProcedurePrintController::class)->name('indoor.procedures.print');

        Route::livewire('doctor-payout', 'pages::payout.doctor')->name('payout.doctor');
        Route::livewire('reception/invoices', 'pages::reception.invoices')->middleware('open.shift')->name('reception.invoices');
        Route::livewire('reception/queue', 'pages::reception.queue')->middleware(['open.shift', RedirectLegacyDisplayDevices::class])->name('reception.queue');
        Route::get('reception/queue/tv', QueueTvController::class)->middleware('open.shift')->name('reception.queue.tv');
        Route::livewire('management/shift-history', 'pages::management.shift-history')->name('management.shift-history');
        Route::livewire('management/approvals', 'pages::management.approvals')->name('management.approvals');
        Route::get('reception/invoices/{invoice}/print', fn (Invoice $invoice) => view('invoices.print', compact('invoice')))->name('invoices.print');

        Route::livewire('reception/mr-lookup', 'pages::reception.mr-lookup')->name('reception.mr-lookup');

        Route::livewire('reception/shift', 'pages::reception.shift')->name('reception.shift');
        Route::livewire('reception/print-jobs', 'pages::reception.print-jobs')->name('reception.print-jobs');

        Route::livewire('lab-api-and-info', 'pages::admin.lab-entries')->name('lab-api-and-info');
        Route::redirect('lab-entries', '/lab-api-and-info');

        Route::livewire('lab/tests', 'pages::lab.tests')->name('lab.tests');
        Route::livewire('lab/tests/{labTest}/fields', 'pages::lab.test-fields')->name('lab.tests.fields');
        Route::livewire('lab/fields', 'pages::lab.fields')->name('lab.fields');

        Route::livewire('admin/notifications', 'pages::admin.notifications')->name('admin.notifications');
        Route::livewire('admin/drive', 'pages::admin.drive')->name('admin.drive');
        Route::get('admin/drive/files/{driveFile}/download', [DriveFileController::class, 'download'])
            ->name('admin.drive.download');
        Route::get('admin/drive/files/{driveFile}/view', [DriveFileController::class, 'view'])
            ->name('admin.drive.view');
        Route::livewire('admin/pdf-print', 'pages::admin.pdf-print')->name('admin.pdf-print');

        Route::middleware('open.shift')->group(function () {
            Route::livewire('reception/walkin', 'pages::reception.walkin')->name('reception.walkin');
            Route::livewire('reception/reservation', 'pages::reception.reservation')->name('reception.reservation');
            Route::livewire('reception/lab-entry', 'pages::reception.lab-entry')->name('reception.lab-entry');
            Route::livewire('reception/vitals', 'pages::reception.vitals')->name('reception.vitals');
            Route::livewire('reception/procedures', 'pages::reception.procedures')->name('reception.procedures');
            Route::get('reception/procedures/{procedure}/file', ProcedureFileController::class)->name('reception.procedures.file');
            Route::get('reception/procedures/{procedure}/print', ProcedurePrintController::class)->name('reception.procedures.print');
            Route::get('reception/procedures/{procedure}/apparent-invoice', ProcedureApparentInvoicePrintController::class)->name('reception.procedures.apparent-invoice');
        });

        Route::livewire('reception/token-flow', 'pages::reception.token-flow')->name('reception.token-flow');
        Route::livewire('supervisor/checklist', 'pages::supervisor.checklist')->name('supervisor.checklist');

        Route::livewire('daily-payout', 'pages::payout.daily')->name('payout.daily');
    });
});

require __DIR__.'/settings.php';
