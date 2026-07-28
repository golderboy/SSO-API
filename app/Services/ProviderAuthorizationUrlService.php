<?php

namespace App\Services;

use App\Enums\IdentityProvider;
use App\Exceptions\BrokerRequestException;
use App\Models\AuthenticationTransaction;

class ProviderAuthorizationUrlService
{
    public function build(
        AuthenticationTransaction $transaction,
        IdentityProvider $provider,
        string $state,
    ): string {
        return match ($provider) {
            IdentityProvider::ThaId => $this->thaIdUrl($state),
            IdentityProvider::ProviderId => $this->healthIdUrl($state),
        };
    }

    private function thaIdUrl(string $state): string
    {
        $this->assertEnabled('services.thaid.enabled');
        $url = $this->httpsUrl('services.thaid.authorization_url');

        return $url.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->stringConfig('services.thaid.client_id'),
            'redirect_uri' => $this->httpsUrl('services.thaid.redirect_uri'),
            'scope' => $this->stringConfig('services.thaid.scopes'),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function healthIdUrl(string $state): string
    {
        $this->assertEnabled('services.moph_id.enabled');
        $baseUrl = rtrim(
            $this->httpsUrl('services.moph_id.health_id.base_url'),
            '/',
        );
        $path = $this->stringConfig(
            'services.moph_id.health_id.authorization_path',
        );

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new BrokerRequestException(
                'server_error',
                'The Health ID authorization path is invalid.',
                503,
            );
        }

        return $baseUrl.$path.'?'.http_build_query([
            'client_id' => $this->stringConfig(
                'services.moph_id.health_id.client_id',
            ),
            'redirect_uri' => $this->httpsUrl(
                'services.moph_id.health_id.redirect_uri',
            ),
            'response_type' => 'code',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function assertEnabled(string $key): void
    {
        if (config($key) !== true) {
            throw new BrokerRequestException(
                'temporarily_unavailable',
                'The selected identity provider is not enabled.',
                503,
            );
        }
    }

    private function httpsUrl(string $key): string
    {
        $value = $this->stringConfig($key);
        $parts = parse_url($value);

        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new BrokerRequestException(
                'server_error',
                'An identity provider URL is invalid.',
                503,
            );
        }

        return $value;
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || trim($value) === '') {
            throw new BrokerRequestException(
                'server_error',
                'The selected identity provider is not configured.',
                503,
            );
        }

        return trim($value);
    }
}
