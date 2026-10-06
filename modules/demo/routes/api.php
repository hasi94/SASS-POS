<?php

use Flexipos\Module\Demo\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')
    ->prefix('api/v1/demo')
    ->group(function (): void {
        Route::get('/ping', [PingController::class, 'index'])->name('demo.ping');
    });
