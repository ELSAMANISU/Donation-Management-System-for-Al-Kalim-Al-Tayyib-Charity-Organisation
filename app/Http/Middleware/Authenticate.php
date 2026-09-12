<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;

class Authenticate extends BaseAuthenticate
{
    protected function unauthenticated($request, array $guards)
    {
        abort_if($request->routeIs('admin.help-applications.in-review.decide', 'admin.help-applications.decided.convert-to-campaign', 'admin.campaigns.publish'), 404);

        parent::unauthenticated($request, $guards);
    }
}
