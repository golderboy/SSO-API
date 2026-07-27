<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationSsoConfig;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ApplicationSsoClientService
{
    public function __construct(
        private readonly ClientRepository $clients,
    ) {}

    /**
     * @param  list<string>  $redirectUris
     * @param  list<string>  $allowedProviders
     * @return array{config: ApplicationSsoConfig, client: Client, plain_secret: string}
     */
    public function create(
        Application $application,
        array $redirectUris,
        array $allowedProviders,
    ): array {
        if ($application->ssoConfig()->exists()) {
            throw new ConflictHttpException(
                'This application already has an SSO client.',
            );
        }

        return DB::transaction(function () use (
            $allowedProviders,
            $application,
            $redirectUris,
        ): array {
            $client = $this->clients->createAuthorizationCodeGrantClient(
                $application->name,
                $redirectUris,
                true,
            );
            $client->forceFill([
                'provider' => 'sso_subjects',
                'grant_types' => ['authorization_code'],
            ])->save();
            $plainSecret = $client->plainSecret;

            if (! is_string($plainSecret) || $plainSecret === '') {
                throw new \RuntimeException(
                    'OAuth client secret was not generated.',
                );
            }

            $config = ApplicationSsoConfig::query()->create([
                'application_id' => $application->id,
                'oauth_client_id' => $client->id,
                'allowed_providers' => $allowedProviders,
            ]);

            return [
                'config' => $config,
                'client' => $client,
                'plain_secret' => $plainSecret,
            ];
        }, 3);
    }

    public function rotateSecret(ApplicationSsoConfig $config): string
    {
        return DB::transaction(function () use ($config): string {
            $client = $config->oauthClient()->lockForUpdate()->firstOrFail();
            $this->clients->regenerateSecret($client);
            $plainSecret = $client->plainSecret;

            if (! is_string($plainSecret) || $plainSecret === '') {
                throw new \RuntimeException(
                    'OAuth client secret was not regenerated.',
                );
            }

            return $plainSecret;
        }, 3);
    }

    public function revoke(ApplicationSsoConfig $config): void
    {
        DB::transaction(function () use ($config): void {
            $client = $config->oauthClient()->lockForUpdate()->firstOrFail();
            $this->clients->delete($client);
            $config->delete();
        }, 3);
    }
}
