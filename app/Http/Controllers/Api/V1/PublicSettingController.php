<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Requests\PublicSettingIndexRequest;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;

/** Read-only, unauthenticated. Serves ONLY is_public = true settings; a private key is indistinguishable from a missing one (404). */
class PublicSettingController extends ApiController
{
    public function __construct(private readonly BusinessSettingsService $settings)
    {
    }

    public function index(PublicSettingIndexRequest $request): JsonResponse
    {
        return $this->ok($this->settings->publicGrouped($request->validated('group')));
    }

    public function show(string $key): JsonResponse
    {
        return $this->ok($this->settings->publicOne($key) ?? throw new ApiException('Resource not found.', 404));
    }
}
