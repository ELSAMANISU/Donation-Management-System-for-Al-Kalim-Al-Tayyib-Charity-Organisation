<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRequiredPasswordHasBeenChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if ($user === null || ! $user->must_change_password || $this->isAllowedRoute($request)) {
            return $next($request);
        }

        return redirect()->route('password.change.required.edit');
    }

    private function isAllowedRoute(Request $request): bool
    {
        return $request->routeIs([
            'password.change.required.edit',
            'password.change.required.update',
            'logout',
        ]);
    }
}
