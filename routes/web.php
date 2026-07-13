<?php

use App\Enums\UserRole;
use App\Http\Controllers\Display\TokenDisplayController;
use App\Http\Middleware\RedirectLegacyDisplayDevices;
use App\Models\Invoice;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('display/tokens', 'pages::display.token-display')
    ->middleware(RedirectLegacyDisplayDevices::class)
    ->name('display.tokens');

Route::get('display/tokens/tv', [TokenDisplayController::class, 'tv'])->name('display.tokens.tv');

Route::post('display/tokens/tv/select', [TokenDisplayController::class, 'selectQueue'])->name('display.tokens.tv.select');
Route::post('display/tokens/tv/next', [TokenDisplayController::class, 'callNext'])->name('display.tokens.tv.next');
Route::post('display/tokens/tv/skip', [TokenDisplayController::class, 'skipCurrent'])->name('display.tokens.tv.skip');
Route::post('display/tokens/tv/recall', [TokenDisplayController::class, 'recallCurrent'])->name('display.tokens.tv.recall');
Route::get('display/tokens/tv/toggle-sidebar', [TokenDisplayController::class, 'toggleSidebar'])->name('display.tokens.tv.toggle-sidebar');

Route::middleware(['auth', 'verified', 'role.assigned'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('pending-role', 'pages::pending-role')->name('pending-role');

    Route::middleware('role:'.UserRole::Admin->value)->group(function () {
        Route::livewire('management/crud', 'pages::management.crud')->name('management.crud');
        Route::livewire('admin/users', 'pages::admin.users')->name('admin.users');
        Route::livewire('admin/sms-logs', 'pages::admin.sms-logs')->name('admin.sms-logs');
    });

    Route::middleware('role:'.UserRole::Doctor->value)->group(function () {
        Route::livewire('doctor/portal', 'pages::doctor.portal')->name('doctor.portal');
    });

    Route::middleware('role:'.UserRole::Management->value)->group(function () {
        Route::livewire('doctor-payout', 'pages::payout.doctor')->name('payout.doctor');
        Route::livewire('reception/invoices', 'pages::reception.invoices')->middleware('open.shift')->name('reception.invoices');
        Route::livewire('reception/queue', 'pages::reception.queue')->middleware('open.shift')->name('reception.queue');
        Route::livewire('management/shift-history', 'pages::management.shift-history')->name('management.shift-history');
        Route::get('reception/invoices/{invoice}/print', fn (Invoice $invoice) => view('invoices.print', compact('invoice')))->name('invoices.print');
    });

    Route::middleware('role:'.UserRole::Receptionist->value.','.UserRole::Management->value)->group(function () {
        Route::livewire('reception/shift', 'pages::reception.shift')->name('reception.shift');
        Route::livewire('reception/print-jobs', 'pages::reception.print-jobs')->name('reception.print-jobs');
    });

    Route::middleware('role:'.UserRole::Receptionist->value)->group(function () {
        Route::middleware('open.shift')->group(function () {
            Route::livewire('reception/walkin', 'pages::reception.walkin')->name('reception.walkin');
            Route::livewire('reception/reservation', 'pages::reception.reservation')->name('reception.reservation');
            Route::livewire('reception/patient-calling', 'pages::reception.patient-calling')->name('reception.patient-calling');
            Route::livewire('reception/lab-entry', 'pages::reception.lab-entry')->name('reception.lab-entry');
            Route::livewire('reception/procedures', 'pages::reception.procedures')->name('reception.procedures');
        });

        Route::livewire('daily-payout', 'pages::payout.daily')->name('payout.daily');
    });
});

require __DIR__.'/settings.php';
