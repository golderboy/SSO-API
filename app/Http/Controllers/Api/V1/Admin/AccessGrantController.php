<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreAccessGrantRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAccessGrantRequest;
use App\Http\Resources\AccessGrantResource;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AccessGrantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max((int) $request->integer('per_page', config('sso.default_page_size')), 1),
            config('sso.max_page_size'),
        );

        $grants = AccessGrant::query()
            ->with(['user', 'application', 'organization'])
            ->when(
                $request->filled('user_id'),
                fn ($query) => $query->whereHas(
                    'user',
                    fn ($user) => $user->where('public_id', $request->query('user_id')),
                ),
            )
            ->when(
                $request->filled('application_id'),
                fn ($query) => $query->whereHas(
                    'application',
                    fn ($application) => $application->where(
                        'public_id',
                        $request->query('application_id'),
                    ),
                ),
            )
            ->latest('id')
            ->paginate($perPage);

        return AccessGrantResource::collection($grants);
    }

    public function store(
        StoreAccessGrantRequest $request,
        AuditLogger $audit,
    ): Response {
        $validated = $request->validated();
        $user = User::query()->where('public_id', $validated['user_id'])->firstOrFail();
        $application = Application::query()
            ->where('public_id', $validated['application_id'])
            ->where('is_active', true)
            ->firstOrFail();
        $organization = isset($validated['organization_id'])
            ? Organization::query()
                ->where('public_id', $validated['organization_id'])
                ->where('is_active', true)
                ->firstOrFail()
            : null;

        if ($application->require_organization_match && $organization === null) {
            abort(422, 'This application requires an organization.');
        }

        $duplicateExists = AccessGrant::query()
            ->effective()
            ->where('user_id', $user->id)
            ->where('application_id', $application->id)
            ->where('organization_id', $organization?->id)
            ->where('role', $validated['role'])
            ->exists();

        if ($duplicateExists) {
            abort(409, 'An effective access grant already exists.');
        }

        $grant = AccessGrant::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'application_id' => $application->id,
            'organization_id' => $organization?->id,
            'role' => $validated['role'],
            'permissions' => $validated['permissions'] ?? [],
            'is_active' => $validated['is_active'] ?? true,
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $audit->log('access_grant.created', $request->user(), $grant);

        return (new AccessGrantResource(
            $grant->load(['user', 'application', 'organization']),
        ))->response()->setStatusCode(201);
    }

    public function show(AccessGrant $accessGrant): AccessGrantResource
    {
        return new AccessGrantResource(
            $accessGrant->load(['user', 'application', 'organization']),
        );
    }

    public function update(
        UpdateAccessGrantRequest $request,
        AccessGrant $accessGrant,
        AuditLogger $audit,
    ): AccessGrantResource {
        if ($accessGrant->revoked_at !== null) {
            abort(409, 'A revoked access grant cannot be updated.');
        }

        $validated = $request->validated();

        if (array_key_exists('organization_id', $validated)) {
            $organization = $validated['organization_id'] === null
                ? null
                : Organization::query()
                    ->where('public_id', $validated['organization_id'])
                    ->where('is_active', true)
                    ->firstOrFail();

            if ($accessGrant->application->require_organization_match && $organization === null) {
                abort(422, 'This application requires an organization.');
            }

            $validated['organization_id'] = $organization?->id;
        }

        $validFrom = $validated['valid_from'] ?? $accessGrant->valid_from;
        $validUntil = $validated['valid_until'] ?? $accessGrant->valid_until;

        if (
            $validFrom !== null
            && $validUntil !== null
            && Carbon::parse($validUntil)->lte(Carbon::parse($validFrom))
        ) {
            abort(422, 'valid_until must be later than valid_from.');
        }

        $accessGrant->update($validated);
        $audit->log('access_grant.updated', $request->user(), $accessGrant);

        return new AccessGrantResource(
            $accessGrant->refresh()->load(['user', 'application', 'organization']),
        );
    }

    public function destroy(
        Request $request,
        AccessGrant $accessGrant,
        AuditLogger $audit,
    ): Response {
        if ($accessGrant->revoked_at === null) {
            $accessGrant->update([
                'is_active' => false,
                'revoked_at' => now(),
                'revoked_by' => $request->user()->id,
            ]);
        }

        $audit->log('access_grant.revoked', $request->user(), $accessGrant);

        return response()->noContent();
    }
}
