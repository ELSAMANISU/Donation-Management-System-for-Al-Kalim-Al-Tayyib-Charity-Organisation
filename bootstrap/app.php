<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureRequiredPasswordHasBeenChanged;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Preserve canonical outcomes and history filters; Form Requests normalize their allowlisted text.
        $middleware->trimStrings(except: [fn ($request) => $request->is('admin/help-applications/in-review/*/duplicate-warnings/*/resolve', 'admin/help-applications/in-review/*/decide', 'admin/help-applications/decided', 'admin/help-applications/decided/*')]);
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
        $exceptions->dontFlash(['identity_document_number', 'document', 'purpose', 'consent', 'resolution_note', 'decision_note']);
    })->create();
