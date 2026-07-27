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
        'system.administrative',
        'throttle:admin-api',
    ])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::prefix('admin')->group(function (): void {
            Route::get('/users', [UserController::class, 'index']);
            Route::get('/users/{user}', [UserController::class, 'show']);
            Route::get('/organizations', [OrganizationController::class, 'index']);
            Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
            Route::get('/applications', [ApplicationController::class, 'index']);
            Route::get('/applications/{application}', [ApplicationController::class, 'show']);
            Route::apiResource('access-grants', AccessGrantController::class);

            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);

            Route::middleware('system.admin')->group(function (): void {
                Route::post('/users', [UserController::class, 'store']);
                Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update']);
                Route::delete('/users/{user}', [UserController::class, 'destroy']);

                Route::post('/organizations', [OrganizationController::class, 'store']);
                Route::match(
                    ['put', 'patch'],
                    '/organizations/{organization}',
                    [OrganizationController::class, 'update'],
                );
                Route::delete(
                    '/organizations/{organization}',
                    [OrganizationController::class, 'destroy'],
                );

                Route::post('/applications', [ApplicationController::class, 'store']);
                Route::match(
                    ['put', 'patch'],
                    '/applications/{application}',
                    [ApplicationController::class, 'update'],
                );
                Route::delete(
                    '/applications/{application}',
                    [ApplicationController::class, 'destroy'],
                );

                Route::post(
                    '/applications/{application}/api-keys',
                    [ApplicationApiKeyController::class, 'store'],
                );
                Route::delete(
                    '/applications/{application}/api-keys/{apiKey}',
                    [ApplicationApiKeyController::class, 'destroy'],
                );
            });
        });
    });
});
