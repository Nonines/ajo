<?php

use App\Http\Controllers\DepositConfirmationController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\SavedDestinationController;
use App\Http\Controllers\SavingsPlanController;
use App\Http\Controllers\TransferApprovalController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth:web', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::get('withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals.index');
    Route::post('withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:10,1')->name('withdrawals.store');
    Route::get('withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])->name('withdrawals.show');
    Route::delete('withdrawals/{withdrawal}', [WithdrawalController::class, 'destroy'])->name('withdrawals.destroy');
    Route::post('destinations', [SavedDestinationController::class, 'store'])->middleware('throttle:10,1')->name('destinations.store');

    Route::prefix('savings')->name('savings.')->controller(SavingsPlanController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
        Route::get('{plan}', 'show')->name('show');
        Route::patch('{plan}', 'update')->middleware('throttle:10,1')->name('update');
    });

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
Route::post('payments/paystack/approval', TransferApprovalController::class)->name('payments.approval');

require __DIR__.'/settings.php';
