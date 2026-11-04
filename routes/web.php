<?php

use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth:web', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('wallet', [WalletController::class, 'index'])->name('wallet.index');
});

require __DIR__.'/settings.php';
