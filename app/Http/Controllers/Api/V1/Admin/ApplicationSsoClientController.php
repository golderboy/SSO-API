<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreApplicationSsoClientRequest;
use App\Models\Application;
use App\Models\ApplicationSsoConfig;
use App\Services\ApplicationSsoClientService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplicationSsoClientController extends Controller
{
    public function show(Application $application): JsonResponse
    {
        $config = $application->ssoConfig()
            ->with('oauthClient')
            ->firstOrFail();

        return response()->json([
            'data' => $this->publicConfig($config),
        ]);
    }

    public function store(
        StoreApplicationSsoClientRequest $request,
        Application $application,
        ApplicationSsoClientService $service,
        AuditLogger $audit,
    ): JsonResponse {
        $validated = $request->validated();
        $result = $service->create(
            $application,
            $validated['redirect_uris'],
            $validated['allowed_providers'],
        );
        $audit->log(
            'application.sso_client_created',
            $request->user(),
            $application,
        );

        return response()->json([
            'data' => [
                ...$this->publicConfig($result['config']->load('oauthClient')),
                'client_secret' => $result['plain_secret'],
            ],
        ], 201)->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    public function rotate(
        Request $request,
        Application $application,
        ApplicationSsoClientService $service,
        AuditLogger $audit,
    ): JsonResponse {
        $config = $application->ssoConfig()->firstOrFail();
        $plainSecret = $service->rotateSecret($config);
        $audit->log(
            'application.sso_client_secret_rotated',
            $request->user(),
            $application,
        );

        return response()->json([
            'data' => [
                'client_id' => $config->oauth_client_id,
                'client_secret' => $plainSecret,
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    public function destroy(
        Request $request,
        Application $application,
        ApplicationSsoClientService $service,
        AuditLogger $audit,
    ): Response {
        $config = $application->ssoConfig()->firstOrFail();
        $service->revoke($config);
        $audit->log(
            'application.sso_client_revoked',
            $request->user(),
            $application,
        );

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function publicConfig(ApplicationSsoConfig $config): array
    {
        return [
            'client_id' => $config->oauth_client_id,
            'redirect_uris' => $config->oauthClient->redirect_uris,
            'allowed_providers' => $config->allowed_providers
                ->map(fn ($provider) => $provider->value)
                ->values()
                ->all(),
            'revoked' => $config->oauthClient->revoked,
        ];
    }
}
