<?php

namespace App\Http\Controllers;

use App\Support\Health\ReadinessProbe;
use App\Support\ModuleHost\ModuleRegistry;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Liveness: the host process is up and able to answer. No database,
     * cache or session connection is opened (T-021 AC-41).
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness: which modules this host process actually booted, and the
     * state of each dependency `ReadinessProbe` knows about (T-021 Ruling
     * 7, AC-42 to AC-46). A lagging tenant never affects this (AC-46): no
     * tenant is consulted anywhere in this method.
     */
    public function ready(ModuleRegistry $registry, ReadinessProbe $probe): JsonResponse
    {
        $result = $probe->run();

        return response()->json([
            'status' => $result['ready'] ? 'ok' : 'unavailable',
            'modules' => $registry->booted(),
            'checks' => $result['checks'],
        ], $result['ready'] ? 200 : 503);
    }
}
