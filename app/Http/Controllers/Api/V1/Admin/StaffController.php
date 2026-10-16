<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\StaffIndexRequest;
use App\Http\Requests\Admin\StaffPasswordRequest;
use App\Http\Requests\Admin\StaffRequest;
use App\Http\Resources\StaffResource;
use App\Http\Responses\ApiResponse;
use App\Models\Admin;
use App\Services\Admin\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff Management. Mounted behind `admin.role:super_admin` (Staff get 403). */
class StaffController extends ApiController
{
    public function __construct(private readonly StaffService $staff)
    {
    }

    public function index(StaffIndexRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->staff->index($request->toQuery()), StaffResource::class);
    }

    public function store(StaffRequest $request): JsonResponse
    {
        return $this->created(StaffResource::make($this->staff->create($request->validated(), $request->user()))->resolve($request), 'Staff account created.');
    }

    public function show(Request $request, Admin $admin): JsonResponse
    {
        return $this->ok(StaffResource::make($admin->load('creator:id,name'))->resolve($request));
    }

    public function update(StaffRequest $request, Admin $admin): JsonResponse
    {
        return $this->ok(StaffResource::make($this->staff->updateStaff($admin, $request->validated()))->resolve($request), 'Staff account updated.');
    }

    public function activate(Request $request, Admin $admin): JsonResponse
    {
        return $this->ok(StaffResource::make($this->staff->activate($admin))->resolve($request), 'Staff account activated.');
    }

    public function deactivate(Request $request, Admin $admin): JsonResponse
    {
        return $this->ok(StaffResource::make($this->staff->deactivate($admin))->resolve($request), 'Staff account deactivated. Active sessions were signed out.');
    }

    public function resetPassword(StaffPasswordRequest $request, Admin $admin): JsonResponse
    {
        $this->staff->resetPassword($admin, $request->validated('password'));

        return $this->ok(null, 'Password reset. The account was signed out everywhere.');
    }
}
