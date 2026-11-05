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
    Route::get('deposits', [DepositController::class, 'index'])->name('deposits.index');
    Route::post('deposits', [DepositController::class, 'store'])->middleware('throttle:10,1')->name('deposits.store');
    Route::get('deposits/callback', [DepositConfirmationController::class, 'show'])->middleware('throttle:30,1')->name('deposits.callback');
    Route::get('deposits/{deposit:reference}', [DepositController::class, 'show'])->name('deposits.show');
    Route::post('deposits/{deposit:reference}/confirmation', [DepositConfirmationController::class, 'store'])->middleware('throttle:10,1')->name('deposits.confirm');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/{notification}', [NotificationController::class, 'update'])->whereUuid('notification')->name('notifications.update');
});

Route::post('payments/paystack/webhook', PaymentWebhookController::class)->name('payments.webhook');

require __DIR__.'/settings.php';
