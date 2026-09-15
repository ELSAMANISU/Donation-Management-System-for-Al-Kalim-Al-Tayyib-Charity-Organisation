<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Throwable;

class PrivateCoordinationResponse
{
    public static function applies(Request $request): bool
    {
        return $request->is('admin/assistance-coordination/*', 'help-applications/*/coordination', 'help-applications/*/coordination/*');
    }

    public function handle(Request $request, Closure $next)
    {
        if (! self::applies($request)) {
            return $next($request);
        }
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $handler = app(ExceptionHandler::class);
            $handler->report($exception);
            $response = $handler->render($request, $exception);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
