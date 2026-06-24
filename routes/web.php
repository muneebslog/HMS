<?php

use App\Enums\UserRole;
use App\Models\Invoice;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::middleware('role:'.UserRole::Admin->value)->group(function () {
        Route::livewire('management/crud', 'pages::management.crud')->name('management.crud');
        Route::livewire('admin/users', 'pages::admin.users')->name('admin.users');
    });

    Route::middleware('role:'.UserRole::Management->value)->group(function () {
        Route::livewire('doctor-payout', 'pages::payout.doctor')->name('payout.doctor');
        Route::livewire('reception/invoices', 'pages::reception.invoices')->name('reception.invoices');
        Route::get('reception/invoices/{invoice}/print', fn (Invoice $invoice) => view('invoices.print', compact('invoice')))->name('invoices.print');
    });

    Route::middleware('role:'.UserRole::Receptionist->value.','.UserRole::Management->value)->group(function () {
        Route::livewire('reception/shift', 'pages::reception.shift')->name('reception.shift');
    });

    Route::middleware('role:'.UserRole::Receptionist->value)->group(function () {
        Route::middleware('open.shift')->group(function () {
            Route::livewire('reception/walkin', 'pages::reception.walkin')->name('reception.walkin');
            Route::livewire('reception/reservation', 'pages::reception.reservation')->name('reception.reservation');
            Route::livewire('reception/lab-entry', 'pages::reception.lab-entry')->name('reception.lab-entry');
            Route::livewire('reception/procedures', 'pages::reception.procedures')->name('reception.procedures');
            Route::livewire('reception/queue', 'pages::reception.queue')->name('reception.queue');
        });

        Route::livewire('daily-payout', 'pages::payout.daily')->name('payout.daily');
    });
});

require __DIR__.'/settings.php';
