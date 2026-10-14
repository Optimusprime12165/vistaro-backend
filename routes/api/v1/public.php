<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('health', [HealthController::class, 'health'])->name('health');
Route::get('ready', [HealthController::class, 'ready'])->name('ready');

// Phase 5: categories, sub-categories, products (list/filter/search, show, related, facets)
