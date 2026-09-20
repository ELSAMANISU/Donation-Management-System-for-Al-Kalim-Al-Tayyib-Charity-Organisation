<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureCoordinationAccount;
use App\Http\Middleware\EnsureRequiredPasswordHasBeenChanged;
use App\Http\Middleware\EnsureSandboxDonationsEnabled;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\PrivateCoordinationResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [PrivateCoordinationResponse::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureCoordinationAccount::class);
        $middleware->prependToPriorityList(EncryptCookies::class, EnsureSandboxDonationsEnabled::class);
        // Preserve canonical outcomes and history filters; Form Requests normalize their allowlisted text.
        $middleware->trimStrings(except: [fn ($request) => PrivateCoordinationResponse::applies($request) || $request->is('admin/help-applications/in-review/*/duplicate-warnings/*/resolve', 'admin/help-applications/in-review/*/decide', 'admin/help-applications/decided', 'admin/help-applications/decided/*', 'ar/cases', 'en/cases', 'ar/cases/*/donate', 'en/cases/*/donate', 'ar/donations/*', 'en/donations/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn ($request) => PrivateCoordinationResponse::applies($request)]);
        $middleware->alias([
            'auth' => Authenticate::class,
            'role' => EnsureUserHasRole::class,
        ]);

        $middleware->web(append: [
            EnsureAccountIsActive::class,
            EnsureRequiredPasswordHasBeenChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function ($response) {
            if (PrivateCoordinationResponse::applies(request())) {
                if (request()->is('admin/aid-delivery/*', 'help-applications/*/aid-delivery/*') && $response->getStatusCode() >= 400) {
                    $response = response('Private sandbox delivery is unavailable. / التسليم التجريبي الخاص غير متاح.', $response->getStatusCode());
                }
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Pragma', 'no-cache');
                $response->headers->set('Referrer-Policy', 'no-referrer');
                $response->headers->set('X-Content-Type-Options', 'nosniff');
            }

            return $response;
        });
        $exceptions->report(function (Throwable $exception) {
            if (! (request()->routeIs('donations.*') || PrivateCoordinationResponse::applies(request()))
                || $exception instanceof AuthenticationException
                || $exception instanceof ValidationException
                || $exception instanceof HttpResponseException
                || $exception instanceof HttpExceptionInterface) {
                return null;
            }
            // Do not attach exception request arguments or the default authenticated-user context.
            try {
                Log::warning(PrivateCoordinationResponse::applies(request()) ? 'Private coordination operation failed.' : 'Sandbox donation operation failed.');
            } catch (Throwable) {
            }

            return false;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! ($request->routeIs('donations.*') || PrivateCoordinationResponse::applies($request))
                || $exception instanceof AuthenticationException
                || $exception instanceof ValidationException
                || $exception instanceof HttpResponseException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500)) {
                return null;
            }
            // Private checkout errors must never render debug request/capability details.
            $message = PrivateCoordinationResponse::applies($request) ? 'Private coordination could not be completed. / تعذر إكمال التنسيق الخاص.' : 'Sandbox checkout could not be completed. / تعذر إكمال الدفع التجريبي.';
            $response = $request->expectsJson() ? response()->json(['message' => $message], 500) : response($message, 500);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        });
        $exceptions->dontFlash(['amount', 'note', 'entry_key', 'idempotency_token', 'identity_document_number', 'document', 'purpose', 'consent', 'resolution_note', 'decision_note', 'body', 'delivery_details']);
    })->create();
