<?php

use App\Http\Controllers\Sso\ProviderSelectionController;
use Illuminate\Support\Facades\Route;

Route::post(
    '/broker/transactions/{transaction}/provider',
    ProviderSelectionController::class,
)->name('sso.broker.select-provider');
