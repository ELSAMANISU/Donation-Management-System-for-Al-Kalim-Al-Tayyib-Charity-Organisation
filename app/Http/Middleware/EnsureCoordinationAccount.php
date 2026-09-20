<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCoordinationAccount
{
    public function handle(Request $request, Closure $next)
    {
        $id = Auth::guard('web')->id();
        $user = $id === null ? null : User::query()->select(['id', 'name', 'email', 'role', 'is_active', 'must_change_password'])->find($id);
        $roles = $request->routeIs('admin.coordination.*', 'admin.aid-delivery.*') ? [UserRole::Admin, UserRole::SuperAdmin] : [UserRole::User];
        abort_unless($user && UserRole::tryFrom((string) $user->getRawOriginal('role')) !== null && $user->is_active && ! $user->must_change_password && $user->hasAnyRole($roles), 404);
        Auth::guard('web')->setUser($user);

        return $next($request);
    }
}
