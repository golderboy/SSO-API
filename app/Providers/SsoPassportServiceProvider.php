<?php

namespace App\Providers;

use DateInterval;
use Laravel\Passport\Bridge;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use RuntimeException;

class SsoPassportServiceProvider extends PassportServiceProvider
{
    public function boot(): void
    {
        Passport::$deviceCodeGrantEnabled = false;
        Passport::$implicitGrantEnabled = false;
        Passport::$passwordGrantEnabled = false;
        Passport::$registersJsonApiRoutes = false;

        Passport::tokensCan([
            'openid' => 'Identify the authenticated SSO subject.',
            'profile' => 'Read the subject profile.',
            'organization' => 'Read effective organization claims.',
            'roles' => 'Read effective application roles and permissions.',
        ]);

        Passport::tokensExpireIn(
            $this->minutesInterval('access_token_ttl_minutes'),
        );
        Passport::refreshTokensExpireIn(
            $this->minutesInterval('refresh_token_ttl_minutes'),
        );

        $keyPath = config('passport.key_path');

        if (is_string($keyPath) && trim($keyPath) !== '') {
            Passport::loadKeysFrom($keyPath);
        }

        parent::boot();
    }

    protected function buildAuthCodeGrant(): AuthCodeGrant
    {
        return new AuthCodeGrant(
            $this->app->make(Bridge\AuthCodeRepository::class),
            $this->app->make(Bridge\RefreshTokenRepository::class),
            $this->minutesInterval('authorization_code_ttl_minutes'),
        );
    }

    private function minutesInterval(string $key): DateInterval
    {
        $minutes = config("sso.oauth.{$key}");

        if (! is_int($minutes) || $minutes < 1 || $minutes > 1440) {
            throw new RuntimeException(
                "Invalid positive OAuth TTL at sso.oauth.{$key}.",
            );
        }

        return new DateInterval("PT{$minutes}M");
    }
}
