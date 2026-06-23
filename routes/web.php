<?php

use App\Models\Invoice;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('management/crud', 'pages::management.crud')->name('management.crud');

    Route::middleware('open.shift')->group(function () {
        Route::livewire('reception/walkin', 'pages::reception.walkin')->name('reception.walkin');
        Route::livewire('reception/reservation', 'pages::reception.reservation')->name('reception.reservation');
        Route::livewire('reception/lab-entry', 'pages::reception.lab-entry')->name('reception.lab-entry');
        Route::livewire('reception/invoices', 'pages::reception.invoices')->name('reception.invoices');
        Route::livewire('reception/queue', 'pages::reception.queue')->name('reception.queue');
        Route::get('reception/invoices/{invoice}/print', fn (Invoice $invoice) => view('invoices.print', compact('invoice')))->name('invoices.print');
    });

    Route::livewire('reception/shift', 'pages::reception.shift')->name('reception.shift');
});

require __DIR__.'/settings.php';
