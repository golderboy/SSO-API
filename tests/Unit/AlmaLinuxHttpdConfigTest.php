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

    public function test_httpd_shares_standard_https_by_server_name(): void
    {
        $this->assertStringContainsString('<VirtualHost *:443>', $this->config);
        $this->assertStringContainsString(
            'ServerName CHANGE-ME.example.invalid',
            $this->config,
        );
        $this->assertSame(1, substr_count($this->config, '<VirtualHost'));
    }

    public function test_httpd_does_not_redeclare_shared_listeners_or_claim_other_hosts(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*Listen\s+443(?:\s|$)/m',
            $this->config,
        );
        $this->assertStringNotContainsString('8089', $this->config);
        $this->assertStringNotContainsString('4343', $this->config);
        $this->assertStringNotContainsString('ServerAlias *', $this->config);
        $this->assertStringNotContainsString('_default_', $this->config);
    }
}
