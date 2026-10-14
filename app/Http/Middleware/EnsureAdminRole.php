<?php

namespace App\Http\Middleware;

use App\Enums\AdminRole;
use App\Exceptions\ApiException;
use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage (after auth:admin + principal:admin):  ->middleware('admin.role:admin,staff')
 * super_admin always passes. Role comes from the authenticated admin row, never from the request.
 */
final class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->user();
        $allowed = array_map(fn (string $r) => AdminRole::from($r), $roles);

        if (! $admin instanceof Admin || ! $admin->hasRole(...$allowed)) {
            throw new ApiException('You do not have permission to perform this action.', 403);
        }

        return $next($request);
    }
}
