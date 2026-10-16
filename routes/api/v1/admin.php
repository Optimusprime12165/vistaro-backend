<?php

use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductImageController;
use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Admin\SubCategoryController;
use Illuminate\Support\Facades\Route;

// Public: admin sign-in (email + password). Mounted under /api/v1/admin
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:admin-login')->name('login');

// Authenticated admin (bearer token whose owner is an Admin and whose ability is "admin")
Route::middleware(['auth:admin', 'principal:admin'])->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    Route::patch('auth/password', [AuthController::class, 'changePassword'])->name('password');

    // ---- Read access: any active admin (super_admin or staff)
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
    Route::get('sub-categories', [SubCategoryController::class, 'index'])->name('sub-categories.index');
    Route::get('sub-categories/{sub_category}', [SubCategoryController::class, 'show'])->name('sub-categories.show');
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('inventory/low-stock', [InventoryController::class, 'lowStock'])->name('inventory.low-stock');

    // ---- Orders: any admin may read; fulfilment actions are open to staff too (cancel / returns / refunds are Super-Admin-only, enforced per target status)
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{id}', [OrderController::class, 'show'])->whereNumber('id')->name('orders.show');
    Route::get('orders/{id}/tracking', [OrderController::class, 'tracking'])->whereNumber('id')->name('orders.tracking');
    Route::get('orders/{id}/label', [OrderController::class, 'label'])->whereNumber('id')->name('orders.label');

    Route::middleware('admin.role:staff')->group(function () {
        Route::post('orders/{id}/transition', [OrderController::class, 'transition'])->whereNumber('id')->name('orders.transition');
        Route::post('orders/{id}/pickup', [OrderController::class, 'pickup'])->whereNumber('id')->name('orders.pickup');
        Route::post('orders/{id}/tracking/sync', [OrderController::class, 'sync'])->whereNumber('id')->name('orders.tracking.sync');
    });

    // ---- Manual SHARED-stock changes (e.g. -2 after two shop sales): Staff and Super Admin. One quantity per size serves online and the shop.
    Route::middleware('admin.role:staff')->group(function () {
        Route::put('products/{product}/stock', [InventoryController::class, 'set'])->name('products.stock.set');
        Route::post('products/{product}/stock/adjust', [InventoryController::class, 'adjust'])->name('products.stock.adjust');
    });

    // ---- Super Admin only (Staff get 403): products, sizes, categories, staff management, refunds, customers, settings
    Route::middleware('admin.role:super_admin')->group(function () {
        // Staff Management (exactly one Super Admin; everybody else is Staff). No role can be set through the API.
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::get('staff/{admin}', [StaffController::class, 'show'])->whereNumber('admin')->name('staff.show');
        Route::patch('staff/{admin}', [StaffController::class, 'update'])->whereNumber('admin')->name('staff.update');
        Route::post('staff/{admin}/activate', [StaffController::class, 'activate'])->whereNumber('admin')->name('staff.activate');
        Route::post('staff/{admin}/deactivate', [StaffController::class, 'deactivate'])->whereNumber('admin')->name('staff.deactivate');
        Route::put('staff/{admin}/password', [StaffController::class, 'resetPassword'])->whereNumber('admin')->name('staff.password');

        Route::post('orders/{id}/refund', [OrderController::class, 'refund'])->whereNumber('id')->name('orders.refund');
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{id}', [CustomerController::class, 'show'])->whereNumber('id')->name('customers.show');
        Route::get('customers/{id}/addresses', [CustomerController::class, 'addresses'])->whereNumber('id')->name('customers.addresses');
        Route::get('login-events', [CustomerController::class, 'loginEvents'])->name('login-events.index');

        // Business settings (dynamic shipping / return rules, store info). Admin only, including reads. Keys look like shipping.flat_fee.
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('settings', [SettingController::class, 'store'])->name('settings.store');
        Route::get('settings/{key}', [SettingController::class, 'show'])->where('key', '[a-z0-9_.]+')->name('settings.show');
        Route::patch('settings/{key}', [SettingController::class, 'update'])->where('key', '[a-z0-9_.]+')->name('settings.update');
        Route::delete('settings/{key}', [SettingController::class, 'destroy'])->where('key', '[a-z0-9_.]+')->name('settings.destroy');

        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::post('sub-categories', [SubCategoryController::class, 'store'])->name('sub-categories.store');
        Route::patch('sub-categories/{sub_category}', [SubCategoryController::class, 'update'])->name('sub-categories.update');
        Route::delete('sub-categories/{sub_category}', [SubCategoryController::class, 'destroy'])->name('sub-categories.destroy');

        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

        Route::post('products/{product}/images', [ProductImageController::class, 'store'])->name('products.images.store');
        Route::patch('products/{product}/images/order', [ProductImageController::class, 'reorder'])->name('products.images.reorder');   // before {image}
        Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');
        Route::patch('products/{product}/images/{image}/primary', [ProductImageController::class, 'primary'])->name('products.images.primary');

        Route::put('products/{product}/colors', [ProductVariantController::class, 'colors'])->name('products.colors');
        Route::put('products/{product}/sizes', [ProductVariantController::class, 'sizes'])->name('products.sizes');
        Route::delete('products/{product}/sizes/{size}', [ProductVariantController::class, 'removeSize'])->name('products.sizes.destroy');
    });

    // Dashboard metrics: Phase 9
});
