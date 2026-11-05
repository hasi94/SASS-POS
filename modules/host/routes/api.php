<?php

use App\Http\Controllers\OpenApiController;
use Illuminate\Support\Facades\Route;

/*
 * T-021 Ruling 8: the generated OpenAPI document, off by default
 * (API_DOCS_ENABLED, D-11). Always registered, so route caching and the
 * toggle stay independent; the controller itself answers 404 when the flag
 * is off.
 */

Route::middleware('api')
    ->prefix('api/v1')
    ->get('/openapi.json', OpenApiController::class)
    ->name('api.openapi');
