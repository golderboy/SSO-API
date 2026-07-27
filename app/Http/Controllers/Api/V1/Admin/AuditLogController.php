<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max((int) $request->integer('per_page', config('sso.default_page_size')), 1),
            config('sso.max_page_size'),
        );

        $logs = AuditLog::query()
            ->with('actor')
            ->when(
                $request->filled('action'),
                fn ($query) => $query->where('action', $request->query('action')),
            )
            ->latest('id')
            ->paginate($perPage);

        return AuditLogResource::collection($logs);
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        return new AuditLogResource($auditLog->load('actor'));
    }
}
