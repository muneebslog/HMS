<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('management/crud', 'pages::management.crud')->name('management.crud');

    Route::middleware('open.shift')->group(function () {
        Route::livewire('reception/walkin', 'pages::reception.walkin')->name('reception.walkin');
        Route::livewire('reception/lab-entry', 'pages::reception.lab-entry')->name('reception.lab-entry');
        Route::livewire('reception/invoices', 'pages::reception.invoices')->name('reception.invoices');
    });

    Route::livewire('reception/shift', 'pages::reception.shift')->name('reception.shift');
});

require __DIR__.'/settings.php';
