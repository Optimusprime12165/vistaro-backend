<?php

namespace App\Services\Auth;

use App\Enums\AccountStatus;
use App\Exceptions\ApiException;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Customer sign-in/sign-up by mobile OR email OTP. There is no password and no separate "register" step. */
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

        $this->assertActive($user);

        DB::transaction(function () use ($user, $mobile, $ip, $userAgent) {
            $user->forceFill(['last_login_at' => now(), 'mobile_verified_at' => $user->mobile_verified_at ?? now()])->save();
            $this->recordLogin($user, ['mobile' => $mobile], 'otp', $ip, $userAgent);
        });

        return ['user' => $user->refresh(), 'token' => $this->issueToken($user), 'is_new_user' => $isNew];
    }

    // ---------------------------------------------------------------- email OTP

    /** @return array<string,mixed> see OtpService::issue */
    public function requestEmailOtp(string $email, ?string $ip, bool $isResend = false): array
    {
        return $this->otp->issue($email, 'login_email', $ip, $isResend);
    }

    /**
     * @return array{user:User,token:\Laravel\Sanctum\NewAccessToken,is_new_user:bool}
     */
    public function verifyEmailOtp(string $email, string $code, ?string $ip, ?string $userAgent): array
    {
        $this->otp->verify($email, $code, 'login_email');

        [$user, $isNew] = $this->findOrCreateUserByEmail($email);

        $this->assertActive($user);

        DB::transaction(function () use ($user, $email, $ip, $userAgent) {
            $user->forceFill(['last_login_at' => now(), 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            $this->recordLogin($user, ['email' => $email], 'email_otp', $ip, $userAgent);
        });

        return ['user' => $user->refresh(), 'token' => $this->issueToken($user), 'is_new_user' => $isNew];
    }

    /** Signed-in customer proves they own the email on their profile. */
    public function requestEmailVerification(User $user, ?string $ip, bool $isResend = false): array
    {
        $this->assertUnverifiedEmail($user);

        return $this->otp->issue((string) $user->email, 'verify_email', $ip, $isResend);
    }

    public function confirmEmailVerification(User $user, string $code): User
    {
        $this->assertUnverifiedEmail($user);
        $this->otp->verify((string) $user->email, $code, 'verify_email');
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->refresh();
    }

    // ---------------------------------------------------------------- internals

    private function assertUnverifiedEmail(User $user): void
    {
        if ($user->email === null || $user->email === '') {
            throw new ApiException('Add an email address to your profile first.', 422, ['email' => ['Add an email address first.']]);
        }
        if ($user->email_verified_at !== null) {
            throw new ApiException('This email address is already verified.', 422);
        }
    }

    private function assertActive(User $user): void
    {
        if (! $user->isActive()) {
            throw new ApiException('This account is not active.', 403);
        }
    }

    /** @param array<string,string> $identity */
    private function recordLogin(User $user, array $identity, string $method, ?string $ip, ?string $userAgent): void
    {
        LoginEvent::query()->create([
            'user_id' => $user->id, 'ip' => $ip, 'method' => $method,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ] + $identity);
    }

    private function issueToken(User $user): \Laravel\Sanctum\NewAccessToken
    {
        return $this->tokens->issue(
            $user,
            'customer',
            (int) config('vistaro.tokens.customer_ttl_minutes'),
            'customer-web',
            (int) config('vistaro.tokens.max_customer_tokens'),
        );
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

    /**
     * Only a VERIFIED email identifies an account. A profile email that was typed in but never verified must not let
     * someone else's email OTP land in that account, so a successful OTP (= proof of ownership) takes the address away
     * from an unverified holder and a fresh account is created for the real owner.
     *
     * @return array{0:User,1:bool}
     */
    private function findOrCreateUserByEmail(string $email): array
    {
        $holder = DB::transaction(function () use ($email) {
            $u = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($u && $u->email_verified_at === null) {
                $u->forceFill(['email' => null])->save();

                return null;
            }

            return $u;
        });

        if ($holder) {
            return [$holder, false];
        }

        try {
            return [User::query()->create(['email' => $email, 'email_verified_at' => now(), 'status' => AccountStatus::Active]), true];
        } catch (UniqueConstraintViolationException) {
            return [User::query()->where('email', $email)->firstOrFail(), false];
        }
    }
}
