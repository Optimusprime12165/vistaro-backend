<?php

use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\SubCategoryController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('health', [HealthController::class, 'health'])->name('health');
Route::get('ready', [HealthController::class, 'ready'])->name('ready');

// Public storefront catalogue (only active, available data is ever returned)
Route::name('catalog.')->group(function () {
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{slug}', [CategoryController::class, 'show'])->name('categories.show');
    Route::get('sub-categories', [SubCategoryController::class, 'index'])->name('sub-categories.index');

    Route::get('products/facets', [ProductController::class, 'facets'])->name('products.facets');   // must precede {slug}
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
    Route::get('products/{slug}/related', [ProductController::class, 'related'])->name('products.related');
});
