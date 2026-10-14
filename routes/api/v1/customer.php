<?php

use App\Http\Controllers\Api\V1\Customer\AuthController;
use App\Http\Controllers\Api\V1\Customer\ProfileController;
use Illuminate\Support\Facades\Route;

// Public: mobile OTP sign-in / sign-up (limits are enforced in the database by OtpService; throttles are burst protection)
Route::prefix('auth/otp')->name('customer.otp.')->group(function () {
    Route::post('request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request')->name('request');
    Route::post('resend', [AuthController::class, 'resendOtp'])->middleware('throttle:otp-request')->name('resend');
    Route::post('verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify')->name('verify');
});

// Authenticated customer (bearer token whose owner is a User and whose ability is "customer")
Route::middleware(['auth:customer', 'principal:customer'])->name('customer.')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    Route::get('me', [ProfileController::class, 'show'])->name('me');
    Route::patch('me', [ProfileController::class, 'update'])->name('me.update');

    // Phase 6: addresses, cart, checkout, orders
});
