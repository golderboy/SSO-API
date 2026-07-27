<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RotateApiKeyRequest;
use App\Models\Application;
use App\Models\ApplicationApiKey;
use App\Services\ApiKeyService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ApplicationApiKeyController extends Controller
{
    public function store(
        RotateApiKeyRequest $request,
        Application $application,
        ApiKeyService $apiKeys,
        AuditLogger $audit,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $issued = DB::transaction(function () use (
                $application,
                $validated,
                $apiKeys,
            ): array {
                if ($validated['revoke_existing'] ?? false) {
                    $application->apiKeys()
                        ->whereNull('revoked_at')
                        ->update(['revoked_at' => now()]);
                }

                return $apiKeys->issue(
                    $application,
                    $validated['name'],
                    $validated['key'] ?? null,
                    $validated['expires_at'] ?? null,
                );
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'key' => $exception->getMessage(),
            ]);
        }

        $audit->log('application_api_key.created', $request->user(), $application, [
            'key_id' => $issued['model']->public_id,
            'key_prefix' => $issued['model']->key_prefix,
            'expires_at' => $issued['model']->expires_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => [
                'id' => $issued['model']->public_id,
                'name' => $issued['model']->name,
                'key' => $issued['plain_text_key'],
                'key_prefix' => $issued['model']->key_prefix,
                'expires_at' => $issued['model']->expires_at,
                'warning' => 'Store this key now. It cannot be retrieved again.',
            ],
        ], 201)->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    public function destroy(
        Request $request,
        Application $application,
        ApplicationApiKey $apiKey,
        AuditLogger $audit,
    ): Response {
        if ($apiKey->application_id !== $application->id) {
            abort(404);
        }

        $apiKey->update(['revoked_at' => $apiKey->revoked_at ?? now()]);
        $audit->log('application_api_key.revoked', $request->user(), $application, [
            'key_id' => $apiKey->public_id,
            'key_prefix' => $apiKey->key_prefix,
        ]);

        return response()->noContent();
    }
}
