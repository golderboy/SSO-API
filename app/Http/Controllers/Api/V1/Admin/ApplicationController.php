<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreApplicationRequest;
use App\Http\Requests\Api\V1\Admin\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Services\ApplicationSsoClientService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApplicationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max((int) $request->integer('per_page', config('sso.default_page_size')), 1),
            config('sso.max_page_size'),
        );

        return ApplicationResource::collection(
            Application::query()->orderBy('name')->paginate($perPage),
        );
    }

    public function store(
        StoreApplicationRequest $request,
        AuditLogger $audit,
    ): Response {
        $application = Application::query()->create([
            'public_id' => (string) Str::uuid(),
            ...$request->validated(),
        ]);
        $audit->log('application.created', $request->user(), $application);

        return (new ApplicationResource($application))->response()->setStatusCode(201);
    }

    public function show(Application $application): ApplicationResource
    {
        return new ApplicationResource($application);
    }

    public function update(
        UpdateApplicationRequest $request,
        Application $application,
        AuditLogger $audit,
    ): ApplicationResource {
        $application->update($request->validated());
        $audit->log('application.updated', $request->user(), $application);

        return new ApplicationResource($application->refresh());
    }

    public function destroy(
        Request $request,
        Application $application,
        ApplicationSsoClientService $ssoClients,
        AuditLogger $audit,
    ): Response {
        DB::transaction(function () use (
            $application,
            $request,
            $ssoClients,
        ): void {
            $ssoConfig = $application->ssoConfig()->first();

            if ($ssoConfig !== null) {
                $ssoClients->revoke($ssoConfig);
            }

            $application->apiKeys()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $application->accessGrants()->whereNull('revoked_at')->update([
                'is_active' => false,
                'revoked_at' => now(),
                'revoked_by' => $request->user()->id,
            ]);
            $application->update(['is_active' => false]);
            $application->delete();
        });

        $audit->log('application.deleted', $request->user(), $application);

        return response()->noContent();
    }
}
