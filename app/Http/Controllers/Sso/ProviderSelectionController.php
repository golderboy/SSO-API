<?php

namespace App\Http\Controllers\Sso;

use App\Enums\IdentityProvider;
use App\Exceptions\BrokerRequestException;
use App\Http\Controllers\Controller;
use App\Models\AuthenticationTransaction;
use App\Services\AuthenticationTransactionService;
use App\Services\ProviderAuthorizationUrlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ProviderSelectionController extends Controller
{
    public function __invoke(
        Request $request,
        AuthenticationTransaction $transaction,
        AuthenticationTransactionService $transactions,
        ProviderAuthorizationUrlService $authorizationUrls,
    ): Response {
        $provider = IdentityProvider::tryFrom(
            (string) $request->input('provider'),
        );

        if ($provider === null) {
            return $this->error(
                new BrokerRequestException(
                    'invalid_request',
                    'The selected identity provider is invalid.',
                ),
            );
        }

        try {
            $transactions->assertProviderSelectable(
                $request,
                $transaction,
                $provider,
            );
            $state = Str::random(64);
            $url = $authorizationUrls->build(
                $transaction,
                $provider,
                $state,
            );
            $transactions->selectProvider(
                $request,
                $transaction,
                $provider,
                $state,
            );

            return new RedirectResponse($url, 302, [
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
            ]);
        } catch (BrokerRequestException $exception) {
            return $this->error($exception);
        }
    }

    private function error(BrokerRequestException $exception): Response
    {
        return response()->view(
            'sso.error',
            [
                'error' => $exception->oauthError,
                'message' => $exception->getMessage(),
            ],
            $exception->status,
            ['Cache-Control' => 'no-store, private'],
        );
    }
}
