<?php

namespace App\Http\Middleware;

use App\Exceptions\BrokerRequestException;
use App\Models\AuthenticationTransaction;
use App\Services\AuthenticationTransactionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BrokerAuthorizationRequest
{
    public function __construct(
        private readonly AuthenticationTransactionService $transactions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $approvedId = null;

        try {
            $approvedId = $request->session()->get(
                'sso.approved_transaction',
            );

            if (is_string($approvedId) && $approvedId !== '') {
                $transaction = $this->transactions->approvedForRequest(
                    $request,
                    $approvedId,
                );
                Auth::guard('sso_web')->login(
                    $transaction->user->ssoSubject,
                );
                $request->attributes->set('sso_broker_approved', true);
                $transaction = $this->transactions->claimForIssuance(
                    $transaction,
                );

                try {
                    $response = $next($request);

                    if ($this->containsAuthorizationCode($response, $transaction)) {
                        $this->transactions->consume($transaction);
                    } else {
                        $this->transactions->denyIssuance($transaction);
                    }
                } catch (Throwable $exception) {
                    $this->transactions->denyIssuance($transaction);

                    throw $exception;
                } finally {
                    $request->session()->forget('sso.approved_transaction');
                    Auth::guard('sso_web')->logout();
                }

                return $response;
            }

            $transaction = $this->transactions->start($request);

            return response()->view(
                'sso.select-provider',
                ['transaction' => $transaction],
                200,
                [
                    'Cache-Control' => 'no-store, private',
                    'Pragma' => 'no-cache',
                ],
            );
        } catch (BrokerRequestException $exception) {
            if (is_string($approvedId) && $approvedId !== '') {
                $request->session()->forget('sso.approved_transaction');
                Auth::guard('sso_web')->logout();
            }

            return response()->view(
                'sso.error',
                [
                    'error' => $exception->oauthError,
                    'message' => $exception->getMessage(),
                ],
                $exception->status,
                [
                    'Cache-Control' => 'no-store, private',
                    'Pragma' => 'no-cache',
                ],
            );
        }
    }

    private function containsAuthorizationCode(
        Response $response,
        AuthenticationTransaction $transaction,
    ): bool {
        if (! $response instanceof RedirectResponse) {
            return false;
        }

        $target = parse_url($response->getTargetUrl());
        $redirect = parse_url(
            (string) $transaction->downstream_request['redirect_uri'],
        );

        if (
            $target === false
            || $redirect === false
            || ($target['scheme'] ?? null) !== ($redirect['scheme'] ?? null)
            || ($target['host'] ?? null) !== ($redirect['host'] ?? null)
            || ($target['port'] ?? null) !== ($redirect['port'] ?? null)
            || ($target['path'] ?? '/') !== ($redirect['path'] ?? '/')
        ) {
            return false;
        }

        parse_str($target['query'] ?? '', $parameters);

        return is_string($parameters['code'] ?? null)
            && $parameters['code'] !== ''
            && is_string($parameters['state'] ?? null)
            && hash_equals(
                $transaction->downstream_request['state'],
                $parameters['state'],
            );
    }
}
