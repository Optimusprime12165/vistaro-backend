<?php

use App\Http\Controllers\Api\V1\Webhooks\DeliveryWebhookController;
use Illuminate\Support\Facades\Route;

// Courier callbacks: authenticated by the provider's signature, not by Sanctum.
Route::post('delivery/{provider}', [DeliveryWebhookController::class, 'handle'])->middleware('throttle:api')->name('delivery');
