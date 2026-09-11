<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($request->routeIs('admin.help-applications.in-review.decide', 'admin.help-applications.decided.convert-to-campaign')) {
            abort_unless($user !== null && $user->is_active && ! $user->must_change_password
                && $user->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]), 404);
        }

        if ($user === null || $user->is_active) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => trans('auth.failed'),
        ]);
    }
}
