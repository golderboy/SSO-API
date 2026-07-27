<?php

use App\Http\Controllers\Api\V1\AccessCheckController;
use App\Http\Controllers\Api\V1\Admin\AccessGrantController;
use App\Http\Controllers\Api\V1\Admin\ApplicationApiKeyController;
use App\Http\Controllers\Api\V1\Admin\ApplicationController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\OrganizationController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:admin-login');

    Route::post('/access/check', AccessCheckController::class)
        ->middleware([
            'throttle:application-auth',
            'application.key',
            'throttle:application-access',
        ]);

    Route::middleware([
        'auth:sanctum',
        'abilities:admin',
        'super.admin',
        'throttle:admin-api',
    ])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::prefix('admin')->group(function (): void {
            Route::apiResource('users', UserController::class);
            Route::apiResource('organizations', OrganizationController::class);
            Route::apiResource('applications', ApplicationController::class);
            Route::apiResource('access-grants', AccessGrantController::class);

            Route::post(
                '/applications/{application}/api-keys',
                [ApplicationApiKeyController::class, 'store'],
            );
            Route::delete(
                '/applications/{application}/api-keys/{apiKey}',
                [ApplicationApiKeyController::class, 'destroy'],
            );

            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);
        });
    });
});
