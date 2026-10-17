<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\OtpCodeRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\UserResource;
use App\Services\Auth\CustomerAuthService;
use App\Services\Catalog\StorefrontCatalogService;
use App\Support\EmailAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends ApiController
{
    public function __construct(private readonly CustomerAuthService $auth, private readonly StorefrontCatalogService $catalog)
    {
    }

    /**
     * The customer's profile plus the public navigation tree, so the storefront can build header/footer/filters
     * from one request. `categories` is exactly what GET /categories returns; guests use that public endpoint.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->ok(
            UserResource::make($request->user())->resolve($request)
            + ['categories' => CategoryResource::collection($this->catalog->categories())->resolve($request)],
        );
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (array_key_exists('email', $data)) {
            $email = EmailAddress::normalize($data['email']);
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

    private function sendVerification(Request $request, bool $resend): JsonResponse
    {
        $user = $request->user();
        $state = $this->auth->requestEmailVerification($user, $request->ip(), $resend);

        return $this->ok(['email' => EmailAddress::mask((string) $user->email)] + $state, 'OTP sent.');
    }
}
