<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\CustomerIndexRequest;
use App\Http\Requests\Admin\LoginEventIndexRequest;
use App\Http\Resources\CustomerAdminResource;
use App\Http\Resources\LoginEventResource;
use App\Http\Responses\ApiResponse;
use App\Services\Fulfilment\CustomerAdminService;
use App\Services\Fulfilment\LoginEventAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends ApiController
{
    public function __construct(private readonly CustomerAdminService $customers, private readonly LoginEventAdminService $logins)
    {
    }

    public function index(CustomerIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->customers->index($request->toQuery()), CustomerAdminResource::class);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->ok(CustomerAdminResource::make($this->customers->show($id))->resolve($request));
    }

    public function loginEvents(LoginEventIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->logins->index($request->toQuery()), LoginEventResource::class);
    }
}
