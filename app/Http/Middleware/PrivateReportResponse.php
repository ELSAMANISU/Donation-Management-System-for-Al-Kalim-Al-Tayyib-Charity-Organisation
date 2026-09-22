<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class PrivateReportResponse
{
    public static function applies(Request $request): bool
    {
        return $request->is('admin/reports', 'admin/reports/*');
    }

    public static function protect(Response $response): Response
    {
        foreach (['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow'] as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    public static function failure(int $status = 500): Response
    {
        return self::protect(response('Private reports are unavailable. / التقارير الخاصة غير متاحة.', $status));
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) {
            return $next($request);
        }
        try {
            return self::protect($next($request));
        } catch (Throwable) {
            // The exception handler handles ordinary route failures. This also protects outer middleware failures.
            return self::failure();
        }
    }
}
