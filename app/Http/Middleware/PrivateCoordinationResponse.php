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
        return $request->is('admin/aid-delivery/*', 'help-applications/*/aid-delivery/*', 'admin/assistance-coordination/*', 'help-applications/*/coordination', 'help-applications/*/coordination/*');
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
        if ($request->is('admin/aid-delivery/*', 'help-applications/*/aid-delivery/*') && $response->getStatusCode() >= 400) {
            $response = response('Private sandbox delivery is unavailable. / التسليم التجريبي الخاص غير متاح.', $response->getStatusCode());
        }
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
