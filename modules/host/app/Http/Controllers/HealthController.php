<?php

namespace App\Http\Controllers;

use App\Support\ServiceHost\ServiceRegistry;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Liveness: the host process is up and able to answer.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness: which service packages this host process actually booted.
     *
     * Deeper checks (database, event bus, consumers) need infrastructure that
     * does not exist yet and are deliberately not reported here.
     */
    public function ready(ServiceRegistry $registry): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'services' => $registry->booted(),
        ]);
    }
}
