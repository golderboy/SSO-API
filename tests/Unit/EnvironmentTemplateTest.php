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
            'THAID_REDIRECT_URI',
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
            'AUDIT_HASH_KEY',
        ] as $name) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($name, '/').'=$/m',
                $template,
            );
        }
    }
}
