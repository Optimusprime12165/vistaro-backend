<?php

use App\Http\Controllers\Api\V1\Customer\AddressController;
use App\Http\Controllers\Api\V1\Customer\AuthController;
use App\Http\Controllers\Api\V1\Customer\CartController;
use App\Http\Controllers\Api\V1\Customer\CheckoutController;
use App\Http\Controllers\Api\V1\Customer\OrderController;
use App\Http\Controllers\Api\V1\Customer\ProfileController;
use Illuminate\Support\Facades\Route;

// Public: mobile OTP sign-in / sign-up (limits are enforced in the database by OtpService; throttles are burst protection)
Route::prefix('auth/otp')->name('customer.otp.')->group(function () {
    Route::post('request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request')->name('request');
    Route::post('resend', [AuthController::class, 'resendOtp'])->middleware('throttle:otp-request')->name('resend');
    Route::post('verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify')->name('verify');
});

// Public: email OTP sign-in / sign-up (same limits and storage as mobile OTP; the email is the identifier)
Route::prefix('auth/email-otp')->name('customer.email-otp.')->group(function () {
    Route::post('request', [AuthController::class, 'requestEmailOtp'])->middleware('throttle:otp-request')->name('request');
    Route::post('resend', [AuthController::class, 'resendEmailOtp'])->middleware('throttle:otp-request')->name('resend');
    Route::post('verify', [AuthController::class, 'verifyEmailOtp'])->middleware('throttle:otp-verify')->name('verify');
});

// Authenticated customer (bearer token whose owner is a User and whose ability is "customer")
Route::middleware(['auth:customer', 'principal:customer'])->name('customer.')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    Route::get('me', [ProfileController::class, 'show'])->name('me');
    Route::patch('me', [ProfileController::class, 'update'])->name('me.update');
    Route::post('me/email/otp', [ProfileController::class, 'requestEmailVerification'])->middleware('throttle:otp-request')->name('me.email.request');
    Route::post('me/email/otp/resend', [ProfileController::class, 'resendEmailVerification'])->middleware('throttle:otp-request')->name('me.email.resend');
    Route::post('me/email/verify', [ProfileController::class, 'verifyEmail'])->middleware('throttle:otp-verify')->name('me.email.verify');

    // Addresses (a customer only ever sees/changes their own)
    Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::get('addresses/{id}', [AddressController::class, 'show'])->whereNumber('id')->name('addresses.show');
    Route::patch('addresses/{id}', [AddressController::class, 'update'])->whereNumber('id')->name('addresses.update');
    Route::delete('addresses/{id}', [AddressController::class, 'destroy'])->whereNumber('id')->name('addresses.destroy');
    Route::patch('addresses/{id}/default', [AddressController::class, 'makeDefault'])->whereNumber('id')->name('addresses.default');

    // Cart (server-priced)
    Route::get('cart', [CartController::class, 'show'])->name('cart.show');
    Route::delete('cart', [CartController::class, 'destroy'])->name('cart.clear');
    Route::post('cart/items', [CartController::class, 'addItem'])->name('cart.items.store');
    Route::patch('cart/items/{id}', [CartController::class, 'updateItem'])->whereNumber('id')->name('cart.items.update');
    Route::delete('cart/items/{id}', [CartController::class, 'removeItem'])->whereNumber('id')->name('cart.items.destroy');

    Route::get('checkout/preview', [CheckoutController::class, 'preview'])->name('checkout.preview');

    // Orders (Idempotency-Key header required on POST /orders)
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('orders/{id}', [OrderController::class, 'show'])->whereNumber('id')->name('orders.show');
    Route::post('orders/{id}/cancel', [OrderController::class, 'cancel'])->whereNumber('id')->name('orders.cancel');
    Route::post('orders/{id}/return', [OrderController::class, 'return'])->whereNumber('id')->name('orders.return');

    // Phase 7: admin-side fulfilment (packing, pickup, AWB, tracking) lives under /admin
});
