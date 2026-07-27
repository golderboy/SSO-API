<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PersonnelIdentityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max((int) $request->integer('per_page', config('sso.default_page_size')), 1),
            config('sso.max_page_size'),
        );
        $search = trim((string) $request->query('search', ''));
        $search = addcslashes(mb_substr($search, 0, 100), '%_\\');

        $users = User::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate($perPage);

        return UserResource::collection($users);
    }

    public function store(
        StoreUserRequest $request,
        PersonnelIdentityService $identity,
        AuditLogger $audit,
    ): Response {
        $validated = $request->validated();
        $cidHash = $identity->hash($validated['cid']);

        if (User::query()->where('cid_hash', $cidHash)->exists()) {
            abort(409, 'A user with this citizen ID already exists.');
        }

        $user = DB::transaction(function () use ($validated, $identity): User {
            $user = new User([
                'public_id' => (string) Str::uuid(),
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'password' => $validated['password'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'system_role' => $validated['system_role'] ?? SystemRole::User->value,
            ]);
            $identity->setCid($user, $validated['cid']);
            $user->save();

            return $user;
        });

        $audit->log('user.created', $request->user(), $user);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(
        UpdateUserRequest $request,
        User $user,
        PersonnelIdentityService $identity,
        AuditLogger $audit,
    ): UserResource {
        $validated = $request->validated();

        if (isset($validated['cid'])) {
            $cidHash = $identity->hash($validated['cid']);

            if (User::query()
                ->where('cid_hash', $cidHash)
                ->whereKeyNot($user->id)
                ->exists()) {
                abort(409, 'A user with this citizen ID already exists.');
            }
        }

        if (
            $request->user()->is($user)
            && (($validated['is_active'] ?? true) === false
                || (isset($validated['system_role'])
                    && $validated['system_role'] !== SystemRole::Admin->value))
        ) {
            abort(422, 'You cannot disable or demote your own administrator account.');
        }

        if ($user->isAdmin() && ! $request->user()->is($user)) {
            abort(422, 'The Admin account cannot be modified by another account.');
        }

        DB::transaction(function () use ($validated, $user, $identity): void {
            $user->fill(collect($validated)->except(['cid'])->all());

            if (array_key_exists('cid', $validated)) {
                $identity->setCid($user, $validated['cid']);
            }

            $user->save();

            if (
                array_key_exists('password', $validated)
                || ! $user->is_active
                || ! $user->isAdministrative()
            ) {
                $user->tokens()->delete();
            }
        });

        $audit->log('user.updated', $request->user(), $user);

        return new UserResource($user->refresh());
    }

    public function destroy(Request $request, User $user, AuditLogger $audit): Response
    {
        if ($request->user()->is($user)) {
            abort(422, 'You cannot delete your own administrator account.');
        }

        if ($user->isAdmin()) {
            abort(422, 'The Admin account cannot be deleted.');
        }

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->accessGrants()->update([
                'is_active' => false,
                'revoked_at' => now(),
            ]);
            $user->delete();
        });

        $audit->log('user.deleted', $request->user(), $user);

        return response()->noContent();
    }
}
