<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Thin base: controllers only validate (FormRequest), authorize (policy/middleware),
 * call a service, and return a Resource through ApiResponse. No business logic here.
 */
abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, ?string $message = null, array $meta = []): JsonResponse
    {
        return ApiResponse::success($data, $message, 200, $meta);
    }

    protected function created(mixed $data = null, ?string $message = 'Created.'): JsonResponse
    {
        return ApiResponse::created($data, $message);
    }

    protected function noContent(): JsonResponse
    {
        return ApiResponse::noContent();
    }
}
