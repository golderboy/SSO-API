<?php

namespace App\Services;

use App\Enums\AuthenticationTransactionStatus;
use App\Enums\IdentityProvider;
use App\Exceptions\BrokerRequestException;
use App\Models\AccessGrant;
use App\Models\ApplicationSsoConfig;
use App\Models\AuthenticationTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use LogicException;

class AuthenticationTransactionService
{
    /**
     * @var list<string>
     */
    private const REQUEST_KEYS = [
        'response_type',
        'client_id',
        'redirect_uri',
        'scope',
        'state',
        'nonce',
        'code_challenge',
        'code_challenge_method',
    ];

    public function start(Request $request): AuthenticationTransaction
    {
        $payload = $this->validatedPayload($request);
        $config = ApplicationSsoConfig::query()
            ->with(['application', 'oauthClient'])
            ->where('oauth_client_id', $payload['client_id'])
            ->first();

        if (
            $config === null
            || $config->application === null
            || ! $config->application->is_active
            || $config->oauthClient === null
            || $config->oauthClient->revoked
            || ! $config->oauthClient->hasGrantType('authorization_code')
        ) {
            throw new BrokerRequestException(
                'unauthorized_client',
                'The OAuth client is not authorized.',
                401,
            );
        }

        if (! in_array(
            $payload['redirect_uri'],
            $config->oauthClient->redirect_uris,
            true,
        )) {
            throw new BrokerRequestException(
                'invalid_request',
                'The redirect URI does not exactly match a registered callback.',
            );
        }

        return AuthenticationTransaction::query()->create([
            'public_id' => (string) Str::uuid(),
            'application_sso_config_id' => $config->id,
            'browser_session_hash' => $this->browserSessionHash($request),
            'downstream_request' => $payload,
            'status' => AuthenticationTransactionStatus::Pending,
            'expires_at' => now()->addMinutes(
                (int) config('sso.oauth.transaction_ttl_minutes'),
            ),
        ])->load(['ssoConfig.application', 'ssoConfig.oauthClient']);
    }

    public function approvedForRequest(
        Request $request,
        string $transactionPublicId,
    ): AuthenticationTransaction {
        $transaction = AuthenticationTransaction::query()
            ->with([
                'user.ssoSubject',
                'ssoConfig.application',
                'ssoConfig.oauthClient',
            ])
            ->where('public_id', $transactionPublicId)
            ->first();

        $effectiveGrant = $transaction === null
            ? null
            : AccessGrant::query()
                ->effective()
                ->with('organization')
                ->whereKey($transaction->access_grant_id)
                ->where('user_id', $transaction->user_id)
                ->where(
                    'application_id',
                    $transaction->ssoConfig?->application_id,
                )
                ->first();

        if (
            $transaction === null
            || $transaction->status !== AuthenticationTransactionStatus::Approved
            || $transaction->isExpired()
            || $transaction->consumed_at !== null
            || ! hash_equals(
                $transaction->browser_session_hash,
                $this->browserSessionHash($request),
            )
            || $transaction->downstream_request
                !== $this->validatedPayload($request)
            || $transaction->user?->ssoSubject === null
            || ! $transaction->user->is_active
            || $transaction->selected_provider === null
            || $effectiveGrant === null
            || ($effectiveGrant->organization_id !== null
                && ($effectiveGrant->organization === null
                    || ! $effectiveGrant->organization->is_active))
            || ($transaction->ssoConfig?->application
                ?->require_organization_match
                && $effectiveGrant->organization_id === null)
            || $effectiveGrant->organization_id
                !== $transaction->organization_id
            || ! $transaction->ssoConfig?->application?->is_active
            || $transaction->ssoConfig?->oauthClient?->revoked
            || ! $transaction->ssoConfig?->oauthClient?->hasGrantType(
                'authorization_code',
            )
            || ! in_array(
                $transaction->downstream_request['redirect_uri'],
                $transaction->ssoConfig?->oauthClient?->redirect_uris ?? [],
                true,
            )
        ) {
            throw new BrokerRequestException(
                'access_denied',
                'The approved authorization transaction is invalid or expired.',
                403,
            );
        }

        return $transaction;
    }

    public function claimForIssuance(
        AuthenticationTransaction $transaction,
    ): AuthenticationTransaction {
        $updated = AuthenticationTransaction::query()
            ->whereKey($transaction->id)
            ->where('status', AuthenticationTransactionStatus::Approved->value)
            ->whereNull('consumed_at')
            ->update([
                'status' => AuthenticationTransactionStatus::Issuing->value,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new BrokerRequestException(
                'invalid_request',
                'The authorization transaction is already being processed.',
                409,
            );
        }

        return $transaction->refresh();
    }

    public function consume(AuthenticationTransaction $transaction): void
    {
        $updated = AuthenticationTransaction::query()
            ->whereKey($transaction->id)
            ->where('status', AuthenticationTransactionStatus::Issuing->value)
            ->whereNull('consumed_at')
            ->update([
                'status' => AuthenticationTransactionStatus::Consumed->value,
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new BrokerRequestException(
                'invalid_request',
                'The authorization transaction has already been consumed.',
            );
        }
    }

    public function denyIssuance(AuthenticationTransaction $transaction): void
    {
        AuthenticationTransaction::query()
            ->whereKey($transaction->id)
            ->where('status', AuthenticationTransactionStatus::Issuing->value)
            ->update([
                'status' => AuthenticationTransactionStatus::Denied->value,
                'updated_at' => now(),
            ]);
    }

    public function selectProvider(
        Request $request,
        AuthenticationTransaction $transaction,
        IdentityProvider $provider,
        string $upstreamState,
    ): AuthenticationTransaction {
        $this->assertProviderSelectable($request, $transaction, $provider);
        $browserSessionHash = $this->browserSessionHash($request);

        $updated = AuthenticationTransaction::query()
            ->whereKey($transaction->id)
            ->where('status', AuthenticationTransactionStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->where('browser_session_hash', $browserSessionHash)
            ->update([
                'selected_provider' => $provider->value,
                'upstream_state_hash' => $this->opaqueValueHash($upstreamState),
                'status' => AuthenticationTransactionStatus::ProviderSelected->value,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new BrokerRequestException(
                'access_denied',
                'The authorization transaction has already been selected.',
                409,
            );
        }

        $request->session()->put(
            'sso.pending_upstream_transaction',
            $transaction->public_id,
        );

        return $transaction->refresh();
    }

    public function assertProviderSelectable(
        Request $request,
        AuthenticationTransaction $transaction,
        IdentityProvider $provider,
    ): void {
        $transaction->loadMissing([
            'ssoConfig.application',
            'ssoConfig.oauthClient',
        ]);
        $browserSessionHash = $this->browserSessionHash($request);

        if (
            $transaction->status !== AuthenticationTransactionStatus::Pending
            || $transaction->isExpired()
            || ! hash_equals(
                $transaction->browser_session_hash,
                $browserSessionHash,
            )
            || ! $transaction->ssoConfig?->application?->is_active
            || $transaction->ssoConfig?->oauthClient === null
            || $transaction->ssoConfig?->oauthClient?->revoked
            || ! $transaction->ssoConfig->oauthClient->hasGrantType(
                'authorization_code',
            )
            || ! $transaction->ssoConfig->allowed_providers->contains(
                $provider,
            )
        ) {
            throw new BrokerRequestException(
                'access_denied',
                'This provider is not allowed for the authorization transaction.',
                403,
            );
        }
    }

    public function opaqueValueHash(string $value): string
    {
        $key = config('sso.transaction_hash_key');

        if (! is_string($key) || strlen($key) < 32) {
            throw new LogicException(
                'TRANSACTION_HASH_KEY must contain at least 32 characters.',
            );
        }

        return hash_hmac('sha256', $value, $key);
    }

    /**
     * @return array<string, string>
     */
    private function validatedPayload(Request $request): array
    {
        $query = $request->query();
        $unexpected = array_diff(array_keys($query), self::REQUEST_KEYS);

        if ($unexpected !== []) {
            throw new BrokerRequestException(
                'invalid_request',
                'The authorization request contains unsupported parameters.',
            );
        }

        $payload = [];

        foreach (self::REQUEST_KEYS as $key) {
            $value = $query[$key] ?? '';

            if (! is_string($value)) {
                throw new BrokerRequestException(
                    'invalid_request',
                    "The {$key} parameter must be a string.",
                );
            }

            $payload[$key] = $value;
        }

        if ($payload['response_type'] !== 'code') {
            throw new BrokerRequestException('unsupported_response_type');
        }

        if (
            $payload['client_id'] === ''
            || strlen($payload['client_id']) > 100
            || $payload['redirect_uri'] === ''
            || strlen($payload['redirect_uri']) > 2048
        ) {
            throw new BrokerRequestException('invalid_request');
        }

        $redirect = parse_url($payload['redirect_uri']);

        if (
            $redirect === false
            || ($redirect['scheme'] ?? null) !== 'https'
            || ! isset($redirect['host'])
            || isset($redirect['user'])
            || isset($redirect['pass'])
            || isset($redirect['fragment'])
        ) {
            throw new BrokerRequestException('invalid_request');
        }

        if (
            preg_match('/^[A-Za-z0-9._~-]{16,512}$/D', $payload['state']) !== 1
            || ($payload['nonce'] !== ''
                && preg_match('/^[A-Za-z0-9._~-]{16,512}$/D', $payload['nonce']) !== 1)
            || preg_match(
                '/^[A-Za-z0-9_-]{43}$/D',
                $payload['code_challenge'],
            ) !== 1
            || $payload['code_challenge_method'] !== 'S256'
        ) {
            throw new BrokerRequestException('invalid_request');
        }

        $scopes = explode(' ', $payload['scope']);

        if (
            $payload['scope'] === ''
            || count($scopes) !== count(array_unique($scopes))
            || collect($scopes)->contains(
                fn (string $scope): bool => $scope === ''
                    || ! Passport::hasScope($scope),
            )
        ) {
            throw new BrokerRequestException('invalid_scope');
        }

        return $payload;
    }

    private function browserSessionHash(Request $request): string
    {
        if (! $request->hasSession() || $request->session()->getId() === '') {
            throw new LogicException(
                'A stateful browser session is required for SSO transactions.',
            );
        }

        return $this->opaqueValueHash($request->session()->getId());
    }
}
