<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('management/crud', 'pages::management.crud')->name('management.crud');

    Route::livewire('reception/walkin', 'pages::reception.walkin')->name('reception.walkin');
    Route::livewire('reception/lab-entry', 'pages::reception.lab-entry')->name('reception.lab-entry');
});

require __DIR__.'/settings.php';
