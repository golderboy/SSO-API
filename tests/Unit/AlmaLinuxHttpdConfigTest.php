<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AlmaLinuxHttpdConfigTest extends TestCase
{
    private string $backendConfig;

    private string $proxyInclude;

    protected function setUp(): void
    {
        $this->backendConfig = (string) file_get_contents(
            dirname(__DIR__, 2).'/deploy/almalinux9/httpd/sso-api.conf.example',
        );
        $this->proxyInclude = (string) file_get_contents(
            dirname(__DIR__, 2).'/deploy/almalinux9/httpd/sso-api-proxy.inc.example',
        );
    }

    public function test_backend_is_only_exposed_on_ipv4_loopback(): void
    {
        $this->assertStringContainsString(
            'Listen 127.0.0.1:8089',
            $this->backendConfig,
        );
        $this->assertStringContainsString(
            '<VirtualHost 127.0.0.1:8089>',
            $this->backendConfig,
        );
        $this->assertStringNotContainsString(
            '<VirtualHost *:443>',
            $this->backendConfig,
        );
        $this->assertStringNotContainsString('4343', $this->backendConfig);
        $this->assertStringNotContainsString('SSLEngine', $this->backendConfig);
    }

    public function test_proxy_include_only_maps_call_prefix_to_the_backend(): void
    {
        $this->assertStringContainsString(
            'ProxyPass "/call/" "http://127.0.0.1:8089/"',
            $this->proxyInclude,
        );
        $this->assertStringContainsString(
            'ProxyPassReverse "/call/" "http://127.0.0.1:8089/"',
            $this->proxyInclude,
        );
        $this->assertStringContainsString(
            'RequestHeader set X-Forwarded-Prefix "/call"',
            $this->proxyInclude,
        );
        $this->assertStringContainsString(
            '<Location "/call/">',
            $this->proxyInclude,
        );
        $this->assertStringContainsString(
            'ProxyPreserveHost On',
            $this->proxyInclude,
        );
        $this->assertStringNotContainsString(
            'RequestHeader set Host',
            $this->proxyInclude,
        );
        $this->assertStringNotContainsString(
            'ProxyPass "/"',
            $this->proxyInclude,
        );
        $this->assertStringNotContainsString(
            '<VirtualHost',
            $this->proxyInclude,
        );
    }
}
