<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuditLogger $audit): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()->where('email', $credentials['email'])->first();
        $passwordHash = $user?->password ?? (string) config('sso.dummy_password_hash');
        $passwordIsValid = Hash::check($credentials['password'], $passwordHash);

        if (
            $user === null
            || ! $user->is_active
            || ! $user->isAdministrative()
            || ! $passwordIsValid
        ) {
            $audit->log('admin.login_failed');

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($credentials['device_name'], ['admin']);
        $audit->log('admin.login_succeeded', $user, $user);

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()?->delete();
        $audit->log('admin.logout', $user, $user);

        return response()->json(['message' => 'Logged out.']);
    }
}
