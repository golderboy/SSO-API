<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_loopback_proxy_headers_reconstruct_the_public_call_url(): void
    {
        Route::middleware('api')->get('/_test/proxy-context', function (Request $request) {
            return [
                'scheme' => $request->getScheme(),
                'host' => $request->getHost(),
                'port' => $request->getPort(),
                'base_url' => $request->getBaseUrl(),
                'root' => $request->root(),
            ];
        });

        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'sobmoeiservice.moph.go.th',
            'HTTP_X_FORWARDED_PORT' => '443',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PREFIX' => '/call',
        ])->getJson('/_test/proxy-context')
            ->assertOk()
            ->assertExactJson([
                'scheme' => 'https',
                'host' => 'sobmoeiservice.moph.go.th',
                'port' => 443,
                'base_url' => '/call',
                'root' => 'https://sobmoeiservice.moph.go.th/call',
            ]);
    }

    public function test_forwarded_headers_from_an_untrusted_client_are_ignored(): void
    {
        Route::middleware('api')->get('/_test/untrusted-proxy', function (Request $request) {
            return [
                'scheme' => $request->getScheme(),
                'host' => $request->getHost(),
                'base_url' => $request->getBaseUrl(),
            ];
        });

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_HOST' => 'localhost',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PREFIX' => '/spoofed',
        ])->getJson('/_test/untrusted-proxy');

        $response
            ->assertOk()
            ->assertJsonPath('base_url', '')
            ->assertJsonMissing(['host' => 'attacker.example']);
    }
}
