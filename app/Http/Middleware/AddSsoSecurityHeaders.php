<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSsoSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $formActionSources = implode(' ', $this->formActionSources());

        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'self'; frame-src 'self'; "
            ."form-action {$formActionSources}; base-uri 'none'; "
            ."frame-ancestors 'none'",
        );
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=()',
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set(
            'Cache-Control',
            'no-store, no-cache, must-revalidate, private',
        );
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Allow form navigation only to this application and the configured
     * upstream identity-provider origins. The provider origins are needed
     * because browsers apply form-action to the provider redirect as well.
     *
     * @return list<string>
     */
    private function formActionSources(): array
    {
        $sources = ["'self'"];

        foreach ([
            config('services.thaid.authorization_url'),
            config('services.moph_id.health_id.base_url'),
        ] as $url) {
            $origin = $this->httpsOrigin($url);

            if ($origin !== null && ! in_array($origin, $sources, true)) {
                $sources[] = $origin;
            }
        }

        return $sources;
    }

    private function httpsOrigin(mixed $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($host)
            || preg_match('/\A[A-Za-z0-9.-]+\z/D', $host) !== 1
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $portSuffix = is_int($port) && $port !== 443 ? ':'.$port : '';

        return 'https://'.strtolower($host).$portSuffix;
    }
}
