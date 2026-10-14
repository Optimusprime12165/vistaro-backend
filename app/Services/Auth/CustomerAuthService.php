<?php

namespace App\Services\Auth;

use App\Enums\AccountStatus;
use App\Exceptions\ApiException;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Customer sign-in/sign-up by mobile OTP. There is no password and no separate "register" step. */
final class CustomerAuthService
{
    public function __construct(private readonly OtpService $otp, private readonly TokenService $tokens)
    {
    }

    /** @return array<string,mixed> see OtpService::issue */
    public function requestOtp(string $mobile, ?string $ip, bool $isResend = false): array
    {
        return $this->otp->issue($mobile, 'login', $ip, $isResend);
    }

    /**
     * @return array{user:User,token:\Laravel\Sanctum\NewAccessToken,is_new_user:bool}
     */
    public function verifyOtp(string $mobile, string $code, ?string $ip, ?string $userAgent): array
    {
        $this->otp->verify($mobile, $code, 'login');   // throws on wrong / expired / locked

        [$user, $isNew] = $this->findOrCreateUser($mobile);

        if (! $user->isActive()) {
            throw new ApiException('This account is not active.', 403);
        }

        DB::transaction(function () use ($user, $mobile, $ip, $userAgent) {
            $user->forceFill(['last_login_at' => now(), 'mobile_verified_at' => $user->mobile_verified_at ?? now()])->save();
            LoginEvent::query()->create([
                'user_id' => $user->id, 'mobile' => $mobile, 'ip' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null, 'method' => 'otp',
            ]);
        });

        $token = $this->tokens->issue(
            $user,
            'customer',
            (int) config('vistaro.tokens.customer_ttl_minutes'),
            'customer-web',
            (int) config('vistaro.tokens.max_customer_tokens'),
        );

        return ['user' => $user->refresh(), 'token' => $token, 'is_new_user' => $isNew];
    }

    /** @return array{0:User,1:bool} */
    private function findOrCreateUser(string $mobile): array
    {
        $existing = User::query()->where('mobile', $mobile)->first();
        if ($existing) {
            return [$existing, false];
        }

        try {
            return [User::query()->create(['mobile' => $mobile, 'mobile_verified_at' => now(), 'status' => AccountStatus::Active]), true];
        } catch (UniqueConstraintViolationException) {
            // Two first-time verifications raced; the other one created the row.
            return [User::query()->where('mobile', $mobile)->firstOrFail(), false];
        }
    }
}
