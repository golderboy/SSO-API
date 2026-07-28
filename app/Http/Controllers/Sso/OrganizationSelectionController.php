<?php

namespace App\Http\Controllers\Sso;

use App\Exceptions\BrokerRequestException;
use App\Http\Controllers\Controller;
use App\Models\AuthenticationTransaction;
use App\Services\AuditLogger;
use App\Services\AuthenticationTransactionService;
use App\Services\SsoAccessGrantPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrganizationSelectionController extends Controller
{
    public function __invoke(
        Request $request,
        AuthenticationTransaction $transaction,
        AuthenticationTransactionService $transactions,
        SsoAccessGrantPolicy $policy,
        AuditLogger $audit,
    ): Response {
        $activeTransaction = null;

        try {
            $activeTransaction = $transactions->pendingOrganizationSelection(
                $request,
                $transaction,
            );
            $grantPublicId = $request->input('access_grant');

            if (
                ! is_string($grantPublicId)
                || preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-'
                        .'[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
                    $grantPublicId,
                ) !== 1
            ) {
                throw new BrokerRequestException(
                    'invalid_request',
                    'The selected organization is invalid.',
                );
            }

            $grants = $policy->eligible(
                $activeTransaction->user,
                $activeTransaction->ssoConfig->application,
            );
            $grant = $grants->first(
                fn ($candidate): bool => hash_equals(
                    $candidate->public_id,
                    $grantPublicId,
                ),
            );

            if ($grant === null) {
                throw new BrokerRequestException(
                    'access_denied',
                    'The selected organization is not authorized.',
                    403,
                );
            }

            $transaction = $transactions->approveWithGrant(
                $request,
                $activeTransaction,
                $activeTransaction->user,
                $grant,
            );
            $audit->log(
                'sso.authorization_approved',
                $transaction->user,
                $transaction,
                [
                    'provider' => $transaction->selected_provider->value,
                    'application_public_id' => $transaction->ssoConfig
                        ->application->public_id,
                ],
            );

            return new RedirectResponse(
                $transactions->downstreamAuthorizationUrl($transaction),
                302,
            );
        } catch (BrokerRequestException $exception) {
            $transactions->deny($request, $activeTransaction);
            $event = $audit->log(
                'sso.authentication_denied',
                null,
                $activeTransaction,
                [
                    'provider' => $activeTransaction?->selected_provider?->value
                        ?? 'unknown',
                    'reason' => $exception->oauthError,
                ],
            );

            return response()->view(
                'sso.error',
                [
                    'error' => 'access_denied',
                    'message' => 'ไม่สามารถยืนยันหน่วยงานที่ได้รับสิทธิได้',
                    'correlationId' => $event->public_id,
                ],
                $exception->status,
            );
        }
    }
}
