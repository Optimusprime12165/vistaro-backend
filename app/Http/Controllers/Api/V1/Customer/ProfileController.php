<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\ApiController;
use App\Exceptions\ApiException;
use App\Http\Requests\Auth\OtpCodeRequest;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\CustomerAuthService;
use App\Support\EmailAddress;
use App\Support\MobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends ApiController
{
    public function __construct(private readonly CustomerAuthService $auth)
    {
    }

    /**
     * The signed-in customer's profile ONLY. Categories are public data and are served by GET /categories; they used
     * to be appended here, which forced every storefront page to wait for an authenticated, uncached request.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->ok(UserResource::make($request->user())->resolve($request));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (array_key_exists('email', $data)) {
            $email = EmailAddress::normalize($data['email']);
            if ($email !== $user->email && $user->email_verified_at !== null) {
                // A VERIFIED email is an identity: it is never swapped for an unverified one through a plain profile edit.
                throw new ApiException('Your verified email address cannot be changed here.', 422, ['email' => ['Your verified email address cannot be changed.']]);
            }
            if (! $user->mobile && $email !== $user->email) {
                // An email-only account signs in WITH this email: it cannot be swapped for an unverified one.
                unset($data['email']);
            } else {
                $data['email'] = $email;
                if ($email !== $user->email) {
                    $data['email_verified_at'] = null;       // a new address must be verified again
                }
            }
        }

        $user->fill($data)->save();

        return $this->ok(UserResource::make($user->refresh())->resolve($request), 'Profile updated.');
    }

    public function requestEmailVerification(Request $request): JsonResponse
    {
        return $this->sendVerification($request, false);
    }

    public function resendEmailVerification(Request $request): JsonResponse
    {
        return $this->sendVerification($request, true);
    }

    public function verifyEmail(OtpCodeRequest $request): JsonResponse
    {
        $user = $this->auth->confirmEmailVerification($request->user(), $request->otp());

        return $this->ok(UserResource::make($user)->resolve($request), 'Email verified.');
    }

    public function requestMobileVerification(RequestOtpRequest $request): JsonResponse
    {
        return $this->sendMobileVerification($request, false);
    }

    public function resendMobileVerification(RequestOtpRequest $request): JsonResponse
    {
        return $this->sendMobileVerification($request, true);
    }

    public function verifyMobile(VerifyOtpRequest $request): JsonResponse
    {
        $user = $this->auth->confirmMobileVerification($request->user(), $request->mobile(), $request->otp());

        return $this->ok(UserResource::make($user)->resolve($request), 'Mobile number verified.');
    }

    private function sendMobileVerification(RequestOtpRequest $request, bool $resend): JsonResponse
    {
        $mobile = $request->mobile();
        $state = $this->auth->requestMobileVerification($request->user(), $mobile, $request->ip(), $resend);

        return $this->ok(['mobile' => MobileNumber::mask($mobile)] + $state, 'OTP sent.');
    }

    private function sendVerification(Request $request, bool $resend): JsonResponse
    {
        $user = $request->user();
        $state = $this->auth->requestEmailVerification($user, $request->ip(), $resend);

        return $this->ok(['email' => EmailAddress::mask((string) $user->email)] + $state, 'OTP sent.');
    }
}
