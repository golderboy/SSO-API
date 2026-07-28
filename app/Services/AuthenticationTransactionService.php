<?php

namespace App\Services;

use App\Enums\AuthenticationTransactionStatus;
use App\Enums\IdentityProvider;
use App\Exceptions\BrokerRequestException;
use App\Models\AccessGrant;
use App\Models\ApplicationSsoConfig;
use App\Models\AuthenticationTransaction;
use App\Models\SsoSubject;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function pendingUpstreamCallback(
        Request $request,
        IdentityProvider $provider,
    ): AuthenticationTransaction {
        $publicId = $request->session()->get(
            'sso.pending_upstream_transaction',
        );
        $transaction = is_string($publicId)
            ? AuthenticationTransaction::query()
                ->with([
                    'ssoConfig.application',
                    'ssoConfig.oauthClient',
                ])
                ->where('public_id', $publicId)
                ->first()
            : null;

        if (
            $transaction === null
            || $transaction->status
                !== AuthenticationTransactionStatus::ProviderSelected
            || $transaction->selected_provider !== $provider
            || $transaction->isExpired()
            || ! hash_equals(
                $transaction->browser_session_hash,
                $this->browserSessionHash($request),
            )
            || ! $transaction->ssoConfig?->application?->is_active
            || $transaction->ssoConfig?->oauthClient === null
            || $transaction->ssoConfig->oauthClient->revoked
            || ! $transaction->ssoConfig->oauthClient->hasGrantType(
                'authorization_code',
            )
            || ! in_array(
                $transaction->downstream_request['redirect_uri'] ?? null,
                $transaction->ssoConfig->oauthClient->redirect_uris,
                true,
            )
            || ! $transaction->ssoConfig->allowed_providers->contains(
                $provider,
            )
        ) {
            throw new BrokerRequestException(
                'access_denied',
                'The upstream authorization transaction is invalid or expired.',
                403,
            );
        }

        return $transaction;
    }

    public function assertUpstreamState(
        AuthenticationTransaction $transaction,
        string $state,
    ): void {
        if (
            preg_match('/^[A-Za-z0-9._~-]{16,512}$/D', $state) !== 1
            || $transaction->upstream_state_hash === null
            || ! hash_equals(
                $transaction->upstream_state_hash,
                $this->opaqueValueHash($state),
            )
        ) {
            throw new BrokerRequestException(
                'access_denied',
                'The upstream authorization state is invalid.',
                403,
            );
        }
    }

    public function claimUpstreamAuthentication(
        AuthenticationTransaction $transaction,
    ): AuthenticationTransaction {
        $updated = AuthenticationTransaction::query()
            ->whereKey($transaction->id)
            ->where(
                'status',
                AuthenticationTransactionStatus::ProviderSelected->value,
            )
            ->where('expires_at', '>', now())
            ->update([
                'status' => AuthenticationTransactionStatus::Authenticating
                    ->value,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new BrokerRequestException(
                'access_denied',
                'The upstream callback has already been processed.',
                409,
            );
        }

        return $transaction->refresh();
    }

    public function requireOrganizationSelection(
        Request $request,
        AuthenticationTransaction $transaction,
        User $user,
    ): AuthenticationTransaction {
        $updated = AuthenticationTransaction::query()
            ->whereKey($transaction->id)
            ->where(
                'status',
                AuthenticationTransactionStatus::Authenticating->value,
            )
            ->update([
                'status' => AuthenticationTransactionStatus::OrganizationRequired->value,
                'user_id' => $user->id,
                'authenticated_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new BrokerRequestException(
                'access_denied',
                'The authorization transaction could not be updated.',
                409,
            );
        }

        $request->session()->forget('sso.pending_upstream_transaction');
        $request->session()->put(
            'sso.pending_organization_transaction',
            $transaction->public_id,
        );
        $request->session()->regenerate();

        return $transaction->refresh();
    }

    public function approveWithGrant(
        Request $request,
        AuthenticationTransaction $transaction,
        User $user,
        AccessGrant $grant,
    ): AuthenticationTransaction {
        $transaction->loadMissing('ssoConfig.application');
        $allowedStatuses = [
            AuthenticationTransactionStatus::Authenticating->value,
            AuthenticationTransactionStatus::OrganizationRequired->value,
        ];

        if (
            ! $user->is_active
            || ! $transaction->ssoConfig?->application?->is_active
            || $grant->user_id !== $user->id
            || $grant->application_id
                !== $transaction->ssoConfig->application_id
        ) {
            throw new BrokerRequestException(
                'access_denied',
                'The access grant does not match the authorization transaction.',
                403,
            );
        }

        DB::transaction(function () use (
            $allowedStatuses,
            $grant,
            $transaction,
            $user,
        ): void {
            $effectiveGrant = AccessGrant::query()
                ->effective()
                ->with('organization')
                ->whereKey($grant->id)
                ->where('user_id', $user->id)
                ->where(
                    'application_id',
                    $transaction->ssoConfig->application_id,
                )
                ->lockForUpdate()
                ->first();

            if (
                $effectiveGrant === null
                || ($transaction->ssoConfig->application
                    ->require_organization_match
                    && $effectiveGrant->organization_id === null)
                || ($effectiveGrant->organization_id !== null
                    && ($effectiveGrant->organization === null
                        || ! $effectiveGrant->organization->is_active))
            ) {
                throw new BrokerRequestException(
                    'access_denied',
                    'The access grant is no longer effective.',
                    403,
                );
            }

            SsoSubject::query()->firstOrCreate(['user_id' => $user->id]);
            $updated = AuthenticationTransaction::query()
                ->whereKey($transaction->id)
                ->whereIn('status', $allowedStatuses)
                ->where('expires_at', '>', now())
                ->update([
                    'status' => AuthenticationTransactionStatus::Approved
                        ->value,
                    'user_id' => $user->id,
                    'access_grant_id' => $effectiveGrant->id,
                    'organization_id' => $effectiveGrant->organization_id,
                    'authenticated_at' => $transaction->authenticated_at
                        ?? now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new BrokerRequestException(
                    'access_denied',
                    'The authorization transaction could not be approved.',
                    409,
                );
            }
        }, 3);

        $request->session()->forget([
            'sso.pending_upstream_transaction',
            'sso.pending_organization_transaction',
        ]);
        $request->session()->put(
            'sso.approved_transaction',
            $transaction->public_id,
        );
        $request->session()->regenerate();

        return $transaction->refresh();
    }

    public function pendingOrganizationSelection(
        Request $request,
        AuthenticationTransaction $transaction,
    ): AuthenticationTransaction {
        $pendingId = $request->session()->get(
            'sso.pending_organization_transaction',
        );
        $transaction->loadMissing([
            'user',
            'ssoConfig.application',
            'ssoConfig.oauthClient',
        ]);

        if (
            ! is_string($pendingId)
            || ! hash_equals($transaction->public_id, $pendingId)
            || $transaction->status
                !== AuthenticationTransactionStatus::OrganizationRequired
            || $transaction->isExpired()
            || ! hash_equals(
                $transaction->browser_session_hash,
                $this->browserSessionHash($request),
            )
            || $transaction->user === null
            || ! $transaction->user->is_active
            || ! $transaction->ssoConfig?->application?->is_active
            || $transaction->ssoConfig?->oauthClient === null
            || $transaction->ssoConfig->oauthClient->revoked
            || ! $transaction->ssoConfig->oauthClient->hasGrantType(
                'authorization_code',
            )
            || ! in_array(
                $transaction->downstream_request['redirect_uri'] ?? null,
                $transaction->ssoConfig->oauthClient->redirect_uris,
                true,
            )
        ) {
            throw new BrokerRequestException(
                'access_denied',
                'The organization selection transaction is invalid or expired.',
                403,
            );
        }

        return $transaction;
    }

    public function deny(
        Request $request,
        ?AuthenticationTransaction $transaction,
    ): void {
        if ($transaction !== null) {
            AuthenticationTransaction::query()
                ->whereKey($transaction->id)
                ->whereNotIn('status', [
                    AuthenticationTransactionStatus::Consumed->value,
                    AuthenticationTransactionStatus::Denied->value,
                ])
                ->update([
                    'status' => AuthenticationTransactionStatus::Denied->value,
                    'updated_at' => now(),
                ]);
        }

        $request->session()->forget([
            'sso.pending_upstream_transaction',
            'sso.pending_organization_transaction',
            'sso.approved_transaction',
        ]);
    }

    public function downstreamAuthorizationUrl(
        AuthenticationTransaction $transaction,
    ): string {
        return url('/authorize').'?'.http_build_query(
            $transaction->downstream_request,
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
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
        if (! $request->hasSession()) {
            throw new LogicException(
                'A stateful browser session is required for SSO transactions.',
            );
        }

        $binding = $request->session()->get('sso.browser_binding');

        if (! is_string($binding) || strlen($binding) !== 64) {
            $binding = Str::random(64);
            $request->session()->put('sso.browser_binding', $binding);
        }

        return $this->opaqueValueHash($binding);
    }
}
