<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApplicationApiKey
{
    public function __construct(private readonly ApiKeyService $apiKeys) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('sso.api_key_header');
        $plainTextKey = $request->header($header);

        if (
            ! is_string($plainTextKey)
            || $plainTextKey === ''
            || strlen($plainTextKey) > 255
        ) {
            return response()->json([
                'message' => 'Application API key is required.',
            ], 401);
        }

        $apiKey = $this->apiKeys->findUsable($plainTextKey);

        if ($apiKey === null) {
            return response()->json([
                'message' => 'Application API key is invalid or expired.',
            ], 401);
        }

        if ($apiKey->last_used_at === null || $apiKey->last_used_at->lt(now()->subMinutes(5))) {
            $apiKey->forceFill(['last_used_at' => now()])->save();
        }
        $request->attributes->set('application', $apiKey->application);
        $request->attributes->set('application_api_key', $apiKey);

        return $next($request);
    }
}
