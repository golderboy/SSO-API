<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('admin-login', function (Request $request): Limit {
            $email = mb_substr(
                strtolower((string) $request->input('email')),
                0,
                255,
            );

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('admin-api', function (Request $request): Limit {
            return Limit::perMinute(120)->by(
                (string) ($request->user()?->public_id ?? $request->ip()),
            );
        });

        RateLimiter::for('application-access', function (Request $request): Limit {
            $apiKey = $request->attributes->get('application_api_key');

            return Limit::perMinute(120)->by(
                (string) ($apiKey?->key_prefix ?? $request->ip()),
            );
        });

        RateLimiter::for('application-auth', function (Request $request): Limit {
            return Limit::perMinute(60)->by((string) $request->ip());
        });
    }
}
