<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowedRoles = array_map(
            static fn (string $role): ?UserRole => UserRole::tryFrom($role),
            $roles,
        );

        $user = $request->user();

        if (
            ! $user instanceof User
            || $allowedRoles === []
            || in_array(null, $allowedRoles, true)
            || ! $user->hasAnyRole($allowedRoles)
        ) {
            abort(403);
        }

        return $next($request);
    }
}
