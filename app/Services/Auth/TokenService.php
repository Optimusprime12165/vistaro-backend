<?php

namespace App\Services\Auth;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The only place that creates or revokes Sanctum tokens, so lifetime / ability / revocation rules
 * are identical for customers and admins.
 *
 * @phpstan-type Tokenable Model&\Laravel\Sanctum\Contracts\HasApiTokens
 */
final class TokenService
{
    /** Issues a token carrying exactly one ability ('customer' or 'admin') and an absolute expiry. */
    public function issue(Model $user, string $ability, int $ttlMinutes, string $name, ?int $maxTokens = null): NewAccessToken
    {
        $token = $user->createToken($name, [$ability], now()->addMinutes($ttlMinutes));

        // Housekeeping: expired tokens are useless; also cap concurrent sessions (oldest revoked first).
        $user->tokens()->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();

        if ($maxTokens !== null) {
            $keep = $user->tokens()->orderByDesc('id')->limit($maxTokens)->pluck('id');
            $user->tokens()->whereNotIn('id', $keep)->delete();
        }

        return $token;
    }

    /** Logout of the current device only. */
    public function revokeCurrent(Model $user): void
    {
        $current = $user->currentAccessToken();

        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }
    }

    /** Logout everywhere. */
    public function revokeAll(Model $user): void
    {
        $user->tokens()->delete();
    }

    /** Revoke every token except the one used for this request (e.g. after a password change). */
    public function revokeOthers(Model $user): void
    {
        $current = $user->currentAccessToken();
        $query = $user->tokens();

        if ($current instanceof PersonalAccessToken) {
            $query->whereKeyNot($current->getKey());
        }

        $query->delete();
    }
}
