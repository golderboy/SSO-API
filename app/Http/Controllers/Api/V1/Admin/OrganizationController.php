<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreOrganizationRequest;
use App\Http\Requests\Api\V1\Admin\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max((int) $request->integer('per_page', config('sso.default_page_size')), 1),
            config('sso.max_page_size'),
        );

        return OrganizationResource::collection(
            Organization::query()->orderBy('hcode')->paginate($perPage),
        );
    }

    public function store(
        StoreOrganizationRequest $request,
        AuditLogger $audit,
    ): Response {
        $organization = Organization::query()->create([
            'public_id' => (string) Str::uuid(),
            ...$request->validated(),
        ]);
        $audit->log('organization.created', $request->user(), $organization);

        return (new OrganizationResource($organization))->response()->setStatusCode(201);
    }

    public function show(Organization $organization): OrganizationResource
    {
        return new OrganizationResource($organization);
    }

    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization,
        AuditLogger $audit,
    ): OrganizationResource {
        $organization->update($request->validated());
        $audit->log('organization.updated', $request->user(), $organization);

        return new OrganizationResource($organization->refresh());
    }

    public function destroy(
        Request $request,
        Organization $organization,
        AuditLogger $audit,
    ): Response {
        if ($organization->accessGrants()->effective()->exists()) {
            abort(409, 'Organization has active access grants.');
        }

        $organization->update(['is_active' => false]);
        $organization->delete();
        $audit->log('organization.deleted', $request->user(), $organization);

        return response()->noContent();
    }
}
