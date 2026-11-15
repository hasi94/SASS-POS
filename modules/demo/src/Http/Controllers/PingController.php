<?php

namespace Flexipos\Module\Demo\Http\Controllers;

use Flexipos\Kernel\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Sample endpoint proving the route prefix of this module is mounted.
 *
 * The in-repo example of the T-021 envelope: every module calls
 * {@see ApiResponse} instead of hand-writing a response body.
 */
class PingController
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'module' => 'demo',
            'status' => 'ok',
        ]);
    }
}
