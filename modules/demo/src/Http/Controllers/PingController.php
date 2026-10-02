<?php

namespace Flexipos\Service\Demo\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Sample endpoint proving the route prefix of this service is mounted.
 *
 * Replace it with real endpoints; the full API envelope is a later backlog row.
 */
class PingController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'service' => 'demo',
            'status' => 'ok',
        ]);
    }
}
