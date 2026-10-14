<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

final class AdminAuthService
{
    public function __construct(private readonly TokenService $tokens)
    {
    }

    /**
     * @return array{admin:Admin,token:\Laravel\Sanctum\NewAccessToken}
     */
    public function login(string $email, string $password, ?string $ip): array
    {
        $admin = Admin::query()->where('email', mb_strtolower($email))->first();

        // Always run one bcrypt comparison so response time does not reveal whether the email exists.
        $hash = $admin?->password ?? Hash::make('timing-equaliser');
        $valid = Hash::check($password, $hash) && $admin !== null;

        if (! $valid) {
            throw new ApiException('The provided credentials are incorrect.', 401);
        }

        if (! $admin->isActive()) {
            throw new ApiException('This account is not active.', 403);
        }

        if (Hash::needsRehash($admin->password)) {
            $admin->password = $password;             // re-hashed by the 'hashed' cast
        }

        $admin->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->save();

        // Single active admin session: signing in elsewhere revokes older tokens.
        $this->tokens->revokeAll($admin);

        $token = $this->tokens->issue($admin, 'admin', (int) config('vistaro.tokens.admin_ttl_minutes'), 'admin-panel');

        return ['admin' => $admin, 'token' => $token];
    }

    public function changePassword(Admin $admin, string $current, string $new): void
    {
        if (! Hash::check($current, $admin->password)) {
            throw new ApiException('The current password is incorrect.', 422, ['current_password' => ['The current password is incorrect.']]);
        }

        $admin->forceFill(['password' => $new])->save();   // hashed by the cast
        $this->tokens->revokeOthers($admin);               // every other session must sign in again
    }
}
