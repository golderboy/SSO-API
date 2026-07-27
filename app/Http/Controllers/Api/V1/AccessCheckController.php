<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AccessCheckRequest;
use App\Models\Application;
use App\Services\AccessDecisionService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

class AccessCheckController extends Controller
{
    public function __invoke(
        AccessCheckRequest $request,
        AccessDecisionService $decisions,
        AuditLogger $audit,
    ): JsonResponse {
        /** @var Application $application */
        $application = $request->attributes->get('application');
        $validated = $request->validated();
        $decision = $decisions->decide(
            $application,
            $validated['cid'],
            $validated['organization_hcode'] ?? null,
        );

        $audit->log(
            $decision['allowed'] ? 'access.allowed' : 'access.denied',
            target: $application,
            context: [
                'reason' => $decision['reason'],
                'organization_hcode' => $validated['organization_hcode'] ?? null,
            ],
        );

        if (! $decision['allowed']) {
            return response()->json([
                'data' => [
                    'allowed' => false,
                    'reason' => 'not_authorized',
                ],
            ]);
        }

        $grant = $decision['grant'];

        return response()->json([
            'data' => [
                'allowed' => true,
                'subject_id' => $decision['user']->public_id,
                'application_id' => $application->public_id,
                'organization' => $grant->organization === null ? null : [
                    'id' => $grant->organization->public_id,
                    'hcode' => $grant->organization->hcode,
                ],
                'role' => $grant->role,
                'permissions' => $grant->permissions ?? [],
            ],
        ]);
    }
}
