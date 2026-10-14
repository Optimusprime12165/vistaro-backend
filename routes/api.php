<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| VISTARO API  —  /api/v1/...
|--------------------------------------------------------------------------
| public.php    catalog + health (no auth)
| customer.php  auth:sanctum + principal:customer   (OTP auth endpoints are public, defined inside)
| admin.php     auth:sanctum + principal:admin      (admin login is public, defined inside)
| webhooks.php  provider callbacks, signature-verified, no Sanctum
*/
Route::prefix('v1')->name('v1.')->group(function () {
    Route::middleware('throttle:api')->group(function () {
        Route::group([], base_path('routes/api/v1/public.php'));
        Route::group([], base_path('routes/api/v1/customer.php'));
        Route::prefix('admin')->name('admin.')->group(base_path('routes/api/v1/admin.php'));
    });

    Route::prefix('webhooks')->name('webhooks.')->group(base_path('routes/api/v1/webhooks.php'));
});
