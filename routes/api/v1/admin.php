<?php

use App\Http\Controllers\Api\V1\Admin\AuthController;
use Illuminate\Support\Facades\Route;

// Public: admin sign-in (email + password). Mounted under /api/v1/admin
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:admin-login')->name('login');

// Authenticated admin (bearer token whose owner is an Admin and whose ability is "admin")
Route::middleware(['auth:admin', 'principal:admin'])->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    Route::patch('auth/password', [AuthController::class, 'changePassword'])->name('password');

    // Phase 5: categories, sub-categories, products, images, sizes, offline stock
    // Phase 7: orders, fulfilment, customers, login-events, dashboard
    // Role-restricted groups use ->middleware('admin.role:admin,staff') (super_admin always passes)
});
