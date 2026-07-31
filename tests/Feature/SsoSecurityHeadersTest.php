<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SsoSecurityHeadersTest extends TestCase
{
    public function test_sso_csp_allows_only_configured_provider_origins(): void
    {
        config([
            'services.thaid.authorization_url' => 'https://imauth.bora.dopa.go.th/api/v2/oauth2/auth/',
            'services.moph_id.health_id.base_url' => 'https://health-id.example.test',
        ]);

        Route::middleware('web')->get(
            '/_test/sso-security-headers',
            fn () => response('OK'),
        );

        $policy = (string) $this->get('/_test/sso-security-headers')
            ->assertOk()
            ->headers
            ->get('Content-Security-Policy');

        $this->assertSame(
            "default-src 'none'; style-src 'self'; frame-src 'self'; "
            ."form-action 'self' https://imauth.bora.dopa.go.th "
            ."https://health-id.example.test; base-uri 'none'; "
            ."frame-ancestors 'none'",
            $policy,
        );
        $this->assertStringNotContainsString(
            "form-action 'self' https:;",
            $policy,
        );
    }

    public function test_sso_csp_does_not_trust_invalid_provider_urls(): void
    {
        config([
            'services.thaid.authorization_url' => 'http://identity.example.test/authorize',
            'services.moph_id.health_id.base_url' => "https://identity.example.test\r\nInjected: value",
        ]);

        Route::middleware('web')->get(
            '/_test/sso-security-headers-invalid',
            fn () => response('OK'),
        );

        $policy = (string) $this->get(
            '/_test/sso-security-headers-invalid',
        )->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("form-action 'self';", $policy);
        $this->assertStringNotContainsString('identity.example.test', $policy);
        $this->assertStringNotContainsString('Injected', $policy);
    }
}
