<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->middleware('auth:web')->group(function () {
    Route::redirect('/', '/settings/profile');

    Route::prefix('profile')->name('profile.')->controller(ProfileController::class)->group(function () {
        Route::get('/', 'edit')->name('edit');
        Route::patch('/', 'update')->name('update');
        Route::delete('/', 'destroy')->middleware('verified')->name('destroy');
    });

    Route::middleware('verified')->group(function () {
        Route::controller(SecurityController::class)->group(function () {
            Route::get('security', 'edit')
                ->middleware(RequirePassword::class)
                ->name('security.edit');

            Route::put('password', 'update')
                ->middleware('throttle:6,1')
                ->name('user-password.update');
        });

        Route::inertia('appearance', 'settings/Appearance')->name('appearance.edit');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
