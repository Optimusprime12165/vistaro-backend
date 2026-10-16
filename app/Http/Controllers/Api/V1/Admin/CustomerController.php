<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\CustomerIndexRequest;
use App\Http\Requests\Admin\LoginEventIndexRequest;
use App\Http\Resources\AddressResource;
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

    /** Read-only: a customer's saved address book (admin only). Orders keep their own address snapshot. */
    public function addresses(Request $request, int $id): JsonResponse
    {
        $customer = $this->customers->show($id);   // 404 when the customer does not exist

        return $this->ok(AddressResource::collection(
            $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        )->resolve($request));
    }

    public function loginEvents(LoginEventIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->logins->index($request->toQuery()), LoginEventResource::class);
    }
}
