<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\RequestEmailOtpRequest;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyEmailOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\CustomerAuthService;
use App\Services\Auth\TokenService;
use App\Support\EmailAddress;
use App\Support\MobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(private readonly CustomerAuthService $auth, private readonly TokenService $tokens)
    {
    }

    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        return $this->sendOtp($request, false);
    }

    public function resendOtp(RequestOtpRequest $request): JsonResponse
    {
        return $this->sendOtp($request, true);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->auth->verifyOtp($request->mobile(), $request->otp(), $request->ip(), $request->userAgent());

        return $this->ok([
            'token' => $result['token']->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $result['token']->accessToken->expires_at?->toIso8601String(),
            'is_new_user' => $result['is_new_user'],
            'user' => UserResource::make($result['user'])->resolve($request),
        ], 'Signed in.');
    }

    public function requestEmailOtp(RequestEmailOtpRequest $request): JsonResponse
    {
        return $this->sendEmailOtp($request, false);
    }

    public function resendEmailOtp(RequestEmailOtpRequest $request): JsonResponse
    {
        return $this->sendEmailOtp($request, true);
    }

    public function verifyEmailOtp(VerifyEmailOtpRequest $request): JsonResponse
    {
        $result = $this->auth->verifyEmailOtp($request->email(), $request->otp(), $request->ip(), $request->userAgent());

        return $this->ok([
            'token' => $result['token']->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $result['token']->accessToken->expires_at?->toIso8601String(),
            'is_new_user' => $result['is_new_user'],
            'user' => UserResource::make($result['user'])->resolve($request),
        ], 'Signed in.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->tokens->revokeCurrent($request->user());

        return $this->ok(null, 'Signed out.');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->tokens->revokeAll($request->user());

        return $this->ok(null, 'Signed out of all devices.');
    }

    private function sendOtp(RequestOtpRequest $request, bool $resend): JsonResponse
    {
        $mobile = $request->mobile();
        $state = $this->auth->requestOtp($mobile, $request->ip(), $resend);

        // The OTP itself is NEVER part of the response.
        return $this->ok(['mobile' => MobileNumber::mask($mobile)] + $state, 'OTP sent.');
    }

    private function sendEmailOtp(RequestEmailOtpRequest $request, bool $resend): JsonResponse
    {
        $email = $request->email();
        $state = $this->auth->requestEmailOtp($email, $request->ip(), $resend);

        // The OTP itself is NEVER part of the response.
        return $this->ok(['email' => EmailAddress::mask($email)] + $state, 'OTP sent.');
    }
}
