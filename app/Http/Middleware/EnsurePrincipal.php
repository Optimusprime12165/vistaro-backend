<?php

namespace App\Http\Middleware;

use App\Contracts\ActiveAccount;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware(['auth:sanctum', 'principal:admin'])  or  'principal:customer'
 *
 * Authorisation is based on WHO the token belongs to (the tokenable model class) AND on the token
 * ability, never on a role string supplied by the client. A customer token can therefore never
 * satisfy `principal:admin`, and vice versa. Suspended accounts are rejected even with a valid token.
 */
final class EnsurePrincipal
{
    private const MAP = [
        'admin' => Admin::class,
        'customer' => User::class,
    ];

    public function handle(Request $request, Closure $next, string $principal): Response
    {
        $class = self::MAP[$principal] ?? null;
        abort_if($class === null, 500, 'Unknown principal.');

        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException();
        }

        if (! $user instanceof $class || ! $user->tokenCan($principal)) {
            throw new ApiException('This action is unauthorized.', 403);
        }

        if ($user instanceof ActiveAccount && ! $user->isActive()) {
            throw new ApiException('This account is not active.', 403);
        }

        return $next($request);
    }
}
