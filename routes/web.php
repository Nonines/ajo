<?php

use App\Http\Controllers\DepositConfirmationController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth:web', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('wallet', [WalletController::class, 'index'])->name('wallet.index');

    Route::prefix('deposits')->name('deposits.')->group(function () {
        Route::get('/', [DepositController::class, 'index'])->name('index');
        Route::post('/', [DepositController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::get('callback', [DepositConfirmationController::class, 'show'])->middleware('throttle:30,1')->name('callback');
        Route::get('{deposit:reference}', [DepositController::class, 'show'])->name('show');
        Route::post('{deposit:reference}/confirmation', [DepositConfirmationController::class, 'store'])->middleware('throttle:10,1')->name('confirm');
    });

    Route::prefix('notifications')->name('notifications.')->controller(NotificationController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::patch('{notification}', 'update')->whereUuid('notification')->name('update');
    });
});

Route::post('payments/paystack/webhook', PaymentWebhookController::class)->name('payments.webhook');

require __DIR__.'/settings.php';
