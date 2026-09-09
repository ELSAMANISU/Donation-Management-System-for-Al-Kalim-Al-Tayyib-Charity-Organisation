<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;

class Authenticate extends BaseAuthenticate
{
    protected function unauthenticated($request, array $guards)
    {
        abort_if($request->routeIs('admin.help-applications.in-review.decide'), 404);

        parent::unauthenticated($request, $guards);
    }
}
