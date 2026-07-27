<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AlmaLinuxHttpdConfigTest extends TestCase
{
    private string $config;

    protected function setUp(): void
    {
        $this->config = (string) file_get_contents(
            dirname(__DIR__, 2).'/deploy/almalinux9/httpd/sso-api.conf.example',
        );
    }

    public function test_httpd_uses_dedicated_non_standard_ports(): void
    {
        $this->assertStringContainsString('Listen 8089', $this->config);
        $this->assertStringContainsString('Listen 4343 https', $this->config);
        $this->assertStringContainsString('<VirtualHost *:8089>', $this->config);
        $this->assertStringContainsString('<VirtualHost *:4343>', $this->config);
        $this->assertStringContainsString(
            'https://CHANGE-ME.example.invalid:4343/',
            $this->config,
        );
    }

    public function test_httpd_does_not_claim_standard_https_or_enable_host_wide_hsts(): void
    {
        $this->assertStringNotContainsString('<VirtualHost *:443>', $this->config);
        $this->assertStringNotContainsString('Listen 443', $this->config);
        $this->assertStringNotContainsString('Strict-Transport-Security', $this->config);
    }
}
