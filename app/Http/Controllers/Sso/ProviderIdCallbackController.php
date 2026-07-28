<?php

namespace App\Http\Controllers\Sso;

use App\Contracts\ProviderIdIdentityProvider;
use App\Enums\IdentityProvider;
use App\Exceptions\BrokerRequestException;
use App\Exceptions\IdentityResolutionException;
use App\Exceptions\UpstreamAuthenticationException;
use App\Http\Controllers\Controller;
use App\Models\AuthenticationTransaction;
use App\Services\AuditLogger;
use App\Services\AuthenticationTransactionService;
use App\Services\IdentityLinkService;
use App\Services\SsoAccessGrantPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProviderIdCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        AuthenticationTransactionService $transactions,
        ProviderIdIdentityProvider $provider,
        IdentityLinkService $identities,
        SsoAccessGrantPolicy $policy,
        AuditLogger $audit,
    ): Response {
        $transaction = null;
        $callbackClaimed = false;

        try {
            $transaction = $transactions->pendingUpstreamCallback(
                $request,
                IdentityProvider::ProviderId,
            );
            $payload = $this->validatedPayload($request);
            $transactions->assertUpstreamState(
                $transaction,
                $payload['state'],
            );
            $transaction = $transactions->claimUpstreamAuthentication(
                $transaction,
            );
            $callbackClaimed = true;

            if (isset($payload['error'])) {
                throw new UpstreamAuthenticationException('provider_denied');
            }

            $identity = $provider->authenticate($payload['code']);
            $user = $identities->resolve($identity);
            $application = $transaction->ssoConfig->application;
            $grants = $policy->eligible(
                $user,
                $application,
                $identity->organizationHcodes,
            );

            if ($grants->isEmpty()) {
                throw new BrokerRequestException(
                    'access_denied',
                    'No effective Provider ID organization grant exists for this application.',
                    403,
                );
            }

            if ($grants->count() > 1) {
                $transactions->requireOrganizationSelection(
                    $request,
                    $transaction,
                    $user,
                );
                $audit->log(
                    'sso.organization_selection_required',
                    $user,
                    $transaction,
                    $this->auditContext($transaction),
                );

                return response()->view(
                    'sso.select-organization',
                    [
                        'transaction' => $transaction->refresh(),
                        'grants' => $grants,
                    ],
                );
            }

            $transaction = $transactions->approveWithGrant(
                $request,
                $transaction,
                $user,
                $grants->sole(),
            );
            $audit->log(
                'sso.authorization_approved',
                $user,
                $transaction,
                $this->auditContext($transaction),
            );

            return new RedirectResponse(
                $transactions->downstreamAuthorizationUrl($transaction),
                302,
            );
        } catch (
            BrokerRequestException
            |IdentityResolutionException
            |UpstreamAuthenticationException $exception
        ) {
            if ($callbackClaimed) {
                $transactions->deny($request, $transaction);
            }
            $reason = $this->reason($exception);
            $event = $audit->log(
                'sso.authentication_denied',
                null,
                $transaction,
                array_filter([
                    'provider' => IdentityProvider::ProviderId->value,
                    'reason' => $reason,
                    'application_public_id' => $transaction?->ssoConfig
                        ?->application?->public_id,
                ]),
            );

            return response()->view(
                'sso.error',
                [
                    'error' => 'access_denied',
                    'message' => 'ไม่สามารถยืนยันตัวตนหรือสิทธิการใช้งานได้',
                    'correlationId' => $event->public_id,
                ],
                $this->status($exception),
            );
        }
    }

    /**
     * @return array{state: string, code?: string, error?: string}
     */
    private function validatedPayload(Request $request): array
    {
        $query = $request->query();
        $state = $query['state'] ?? null;
        $hasError = array_key_exists('error', $query);
        $allowed = $hasError
            ? ['error', 'error_description', 'error_uri', 'state']
            : ['code', 'state'];

        if (
            array_diff(array_keys($query), $allowed) !== []
            || ! is_string($state)
            || preg_match('/^[A-Za-z0-9._~-]{16,512}$/D', $state) !== 1
        ) {
            throw new BrokerRequestException(
                'invalid_request',
                'The Provider ID callback is invalid.',
            );
        }

        if ($hasError) {
            $error = $query['error'];

            if (
                ! is_string($error)
                || preg_match('/^[A-Za-z0-9._~-]{1,100}$/D', $error) !== 1
                || ! $this->validOptionalText(
                    $query['error_description'] ?? null,
                )
                || ! $this->validOptionalText($query['error_uri'] ?? null)
            ) {
                throw new BrokerRequestException(
                    'invalid_request',
                    'The Provider ID error callback is invalid.',
                );
            }

            return ['state' => $state, 'error' => $error];
        }

        $code = $query['code'] ?? null;

        if (
            ! is_string($code)
            || strlen($code) < 8
            || strlen($code) > 2048
            || preg_match('/[\x00-\x20\x7F]/', $code) === 1
        ) {
            throw new BrokerRequestException(
                'invalid_request',
                'The Provider ID authorization code is invalid.',
            );
        }

        return ['state' => $state, 'code' => $code];
    }

    private function validOptionalText(mixed $value): bool
    {
        return $value === null
            || (is_string($value)
                && strlen($value) <= 1024
                && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1);
    }

    /**
     * @return array<string, string>
     */
    private function auditContext(
        AuthenticationTransaction $transaction,
    ): array {
        return [
            'provider' => IdentityProvider::ProviderId->value,
            'application_public_id' => $transaction->ssoConfig
                ->application->public_id,
        ];
    }

    private function reason(
        BrokerRequestException
        |IdentityResolutionException
        |UpstreamAuthenticationException $exception,
    ): string {
        return match (true) {
            $exception instanceof IdentityResolutionException => $exception
                ->reason,
            $exception instanceof UpstreamAuthenticationException => $exception
                ->reason,
            default => $exception->oauthError,
        };
    }

    private function status(
        BrokerRequestException
        |IdentityResolutionException
        |UpstreamAuthenticationException $exception,
    ): int {
        return match (true) {
            $exception instanceof BrokerRequestException => $exception->status,
            $exception instanceof IdentityResolutionException => 403,
            $exception->reason === 'provider_denied' => 403,
            default => 502,
        };
    }
}
