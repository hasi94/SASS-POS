<?php

namespace Flexipos\Module\Demo\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Sample endpoint proving the route prefix of this module is mounted.
 *
 * Replace it with real endpoints; the full API envelope is a later backlog row.
 */
class PingController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'module' => 'demo',
            'status' => 'ok',
        ]);
    }
}
