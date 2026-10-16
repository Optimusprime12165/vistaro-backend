<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\SettingIndexRequest;
use App\Http\Requests\Admin\SettingStoreRequest;
use App\Http\Requests\Admin\SettingUpdateRequest;
use App\Http\Resources\BusinessSettingResource;
use App\Http\Responses\ApiResponse;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin-only (route group `admin.role:super_admin`; staff get 403). */
class SettingController extends ApiController
{
    public function __construct(private readonly BusinessSettingsService $settings)
    {
    }

    public function index(SettingIndexRequest $request): JsonResponse
    {
        $q = $request->toQuery();
        $filters = $q->filters;
        if (array_key_exists('is_public', $filters)) {
            $filters['is_public'] = filter_var($filters['is_public'], FILTER_VALIDATE_BOOLEAN);
        }

        return ApiResponse::paginated($this->settings->paginate($filters + ['q' => $q->search], $q->perPage, $q->page), BusinessSettingResource::class);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        return $this->ok(BusinessSettingResource::make($this->settings->find($key))->resolve($request));
    }

    public function store(SettingStoreRequest $request): JsonResponse
    {
        $row = $this->settings->create($request->validated());

        return $this->created(BusinessSettingResource::make($row)->resolve($request), 'Setting created.');
    }

    public function update(SettingUpdateRequest $request, string $key): JsonResponse
    {
        $row = $this->settings->update($key, $request->changes());

        return $this->ok(BusinessSettingResource::make($row)->resolve($request), 'Setting updated.');
    }

    public function destroy(string $key): JsonResponse
    {
        $this->settings->delete($key);

        return $this->ok(null, 'Setting deleted.');
    }
}
