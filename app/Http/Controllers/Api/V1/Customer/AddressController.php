<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Customer\AddressRequest;
use App\Http\Resources\AddressResource;
use App\Services\Commerce\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends ApiController
{
    public function __construct(private readonly AddressService $addresses)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->ok(AddressResource::collection($this->addresses->list($request->user()))->resolve($request));
    }

    public function store(AddressRequest $request): JsonResponse
    {
        return $this->created(AddressResource::make($this->addresses->create($request->user(), $request->payload()))->resolve($request), 'Address saved.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->ok(AddressResource::make($this->addresses->find($request->user(), $id))->resolve($request));
    }

    public function update(AddressRequest $request, int $id): JsonResponse
    {
        $address = $this->addresses->find($request->user(), $id);

        return $this->ok(AddressResource::make($this->addresses->update($request->user(), $address, $request->payload()))->resolve($request), 'Address updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->addresses->delete($request->user(), $this->addresses->find($request->user(), $id));

        return $this->ok(null, 'Address deleted.');
    }

    public function makeDefault(Request $request, int $id): JsonResponse
    {
        $address = $this->addresses->setDefault($request->user(), $this->addresses->find($request->user(), $id));

        return $this->ok(AddressResource::make($address)->resolve($request), 'Default address updated.');
    }
}
