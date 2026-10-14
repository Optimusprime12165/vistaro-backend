<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Resources\AdminResource;
use App\Services\Auth\AdminAuthService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(private readonly AdminAuthService $auth, private readonly TokenService $tokens)
    {
    }

    public function login(AdminLoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->validated('email'), $request->validated('password'), $request->ip());

        return $this->ok([
            'token' => $result['token']->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $result['token']->accessToken->expires_at?->toIso8601String(),
            'admin' => AdminResource::make($result['admin'])->resolve($request),
        ], 'Signed in.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(AdminResource::make($request->user())->resolve($request));
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->changePassword($request->user(), $request->validated('current_password'), $request->validated('password'));

        return $this->ok(null, 'Password updated. Other sessions have been signed out.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->tokens->revokeCurrent($request->user());

        return $this->ok(null, 'Signed out.');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->tokens->revokeAll($request->user());

        return $this->ok(null, 'Signed out of all sessions.');
    }
}
