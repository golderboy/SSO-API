<?php

use App\Http\Controllers\Sso\OrganizationSelectionController;
use App\Http\Controllers\Sso\ProviderSelectionController;
use App\Http\Controllers\Sso\ThaIdCallbackController;
use Illuminate\Support\Facades\Route;

Route::post(
    '/broker/transactions/{transaction}/provider',
    ProviderSelectionController::class,
)->middleware('throttle:sso-browser')
    ->name('sso.broker.select-provider');

Route::get(
    '/sso/callback/thaid',
    ThaIdCallbackController::class,
)->middleware('throttle:sso-browser')
    ->name('sso.callback.thaid');

Route::post(
    '/broker/transactions/{transaction}/organization',
    OrganizationSelectionController::class,
)->middleware('throttle:sso-browser')
    ->name('sso.broker.select-organization');
