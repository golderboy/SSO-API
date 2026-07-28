<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class EnvironmentTemplateTest extends TestCase
{
    public function test_almalinux_template_contains_required_provider_credentials(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2).'/.env.almalinux.example',
        );

        foreach ([
            'THAID_CLIENT_ID',
            'THAID_CLIENT_SECRET',
            'HEALTH_ID_CLIENT_ID',
            'HEALTH_ID_CLIENT_SECRET',
            'HEALTH_ID_REDIRECT_URI',
            'PROVIDER_ID_CLIENT_ID',
            'PROVIDER_ID_SECRET_KEY',
        ] as $name) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($name, '/').'=$/m',
                $template,
            );
        }

        $this->assertMatchesRegularExpression(
            '#^THAID_REDIRECT_URI='
                .'https://sobmoeiservice\.moph\.go\.th/'
                .'call/sso/callback/thaid$#m',
            $template,
        );
    }

    public function test_almalinux_template_contains_no_application_or_lookup_secret(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2).'/.env.almalinux.example',
        );

        foreach ([
            'APP_KEY',
            'DB_PASSWORD',
            'CID_LOOKUP_KEY',
            'PROVIDER_CID_LOOKUP_KEY',
            'EXTERNAL_SUBJECT_LOOKUP_KEY',
            'TRANSACTION_HASH_KEY',
            'AUDIT_HASH_KEY',
            'PASSPORT_PRIVATE_KEY',
            'PASSPORT_PUBLIC_KEY',
        ] as $name) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($name, '/').'=$/m',
                $template,
            );
        }
    }

    public function test_almalinux_template_enforces_approved_local_lifetimes(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2).'/.env.almalinux.example',
        );

        foreach ([
            'SESSION_LIFETIME=30',
            'SANCTUM_EXPIRATION_MINUTES=30',
            'SSO_AUTHORIZATION_CODE_TTL_MINUTES=5',
            'SSO_TRANSACTION_TTL_MINUTES=5',
            'SSO_ACCESS_TOKEN_TTL_MINUTES=30',
            'SSO_REFRESH_TOKEN_TTL_MINUTES=30',
            'PASSPORT_KEY_PATH=/etc/sobmoei/oauth',
            'THAID_CLOCK_SKEW_SECONDS=60',
            'THAID_DISCOVERY_CACHE_SECONDS=300',
            'THAID_JWKS_CACHE_SECONDS=300',
        ] as $setting) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($setting, '/').'$/m',
                $template,
            );
        }
    }
}
