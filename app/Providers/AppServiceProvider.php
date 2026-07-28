<?php

namespace App\Providers;

use App\Contracts\ThaIdIdentityProvider;
use App\Services\ThaIdIdentityProvider as ThaIdIdentityProviderService;
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
        $this->app->bind(
            ThaIdIdentityProvider::class,
            ThaIdIdentityProviderService::class,
        );
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

        RateLimiter::for('sso-browser', function (Request $request): Limit {
            $binding = $request->hasSession()
                ? $request->session()->get('sso.browser_binding')
                : null;

            return Limit::perMinute(30)->by(
                (is_string($binding) ? $binding : 'no-session')
                    .'|'.$request->ip(),
            );
        });
    }
}
