<?php

namespace App\Providers;

use App\Contracts\DonationGateway;
use App\Services\SandboxDonationGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SandboxDonationGateway::class, function ($app) {
            abort_unless($app['config']->get('donations.driver') === 'sandbox', 404);

            return new SandboxDonationGateway;
        });
        $this->app->bind(DonationGateway::class, fn ($app) => $app->make(SandboxDonationGateway::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['aid-delivery-read' => 30, 'aid-delivery-start' => 6, 'aid-delivery-problem' => 6, 'aid-delivery-resume' => 6, 'aid-delivery-success' => 6, 'coordination-read' => 30, 'coordination-start' => 6, 'coordination-respond' => 6, 'coordination-correct' => 6, 'coordination-confirm' => 6, 'coordination-message' => 10, 'donation-entry' => 6, 'donation-outcome' => 10, 'donation-checkout' => 30, 'donation-result' => 30] as $name => $maximum) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($maximum)->by($request->user()?->id ?? $request->ip()));
        }
    }
}
